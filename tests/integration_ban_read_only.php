<?php
/** MLP-359: реальные auth/social/remember и санкции. Только CLI и Docker db.
 * Прямой SQL используется для фикстур auth_tokens/user_socials и проверки отсутствия записи;
 * users создаются/изменяются через UserManager, временные сроки — контролируемая фикстура.
 */
use Domain\Auth;
use Domain\UserManager;
use Domain\ChatManager;
use Core\UserError;
use Infra\Database;
use Social\SocialProvider;
use Social\SocialAuthService;
require_once __DIR__ . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || (it_config()['db']['host'] ?? '') !== 'db') it_skip('CLI Docker db required');
$conn = it_require_db();
ob_start();
Auth::check();
$um = new UserManager();
$chat = new ChatManager();
$login = 'it_banauth_' . bin2hex(random_bytes(5));
$uid = $um->createUser($login, 'test-only-password', 'user', 'Ban auth fixture');
$other = $um->createUser($login . '_other', 'test-only-password', 'user', 'Other fixture');
$provider = new class($login) implements SocialProvider {
    public function __construct(private string $uid) {}
    public function getName(): string { return 'telegram'; }
    public function validateCallback(array $data): ?array {
        return !empty($data['valid']) ? ['id'=>$this->uid, 'username'=>'fixture', 'first_name'=>'Test', 'last_name'=>'', 'photo_url'=>''] : null;
    }
};
$svc = new SocialAuthService();
$count = fn() => (int)$conn->query('SELECT COUNT(*) n FROM chat_messages WHERE user_id=' . $uid)->fetch_assoc()['n'];
$reject = function(string $contains) use ($chat, $uid, $count) {
    $before = $count();
    try { $chat->addMessage($uid, 'Fixture', 'Must never be written'); check(false, 'санкция должна отклонить сообщение'); }
    catch (UserError $e) { check(str_contains($e->getMessage(), $contains), 'UserError содержит ' . $contains); }
    check($count() === $before, 'отказ не создаёт сообщение');
};
$token = function(int $userId, int $delta = 3600, string $validator = 'fixture-validator') use ($conn) {
    $selector = bin2hex(random_bytes(12)); $hash = hash('sha256', $validator); $expires = gmdate('Y-m-d H:i:s', time()+$delta);
    $stmt = $conn->prepare('INSERT INTO auth_tokens(selector,validator_hash,user_id,expires_at) VALUES(?,?,?,?)');
    $stmt->bind_param('ssis', $selector, $hash, $userId, $expires); $stmt->execute();
    $_SESSION=[]; $_COOKIE[Auth::REMEMBER_COOKIE]=$selector . ':' . $validator;
    return $selector;
};
try {
    $um->banUser($uid, 'fixture reason');
    check(Auth::login($login, 'test-only-password'), 'password login succeeds while banned');
    check(Auth::role() === 'user', 'login does not elevate role');
    $_SESSION=[];
    check(!Auth::login($login, 'incorrect'), 'wrong password rejected');
    check(!Auth::check(), 'wrong password leaves no session');
    $um->linkSocial($uid, 'telegram', $provider->validateCallback(['valid'=>true]));
    check(!$svc->handleLogin($provider, [])['success'], 'invalid callback rejected');
    check(!Auth::check(), 'invalid callback creates no session');
    check($svc->handleLogin($provider, ['valid'=>true])['success'], 'linked social login succeeds while banned');
    check(Auth::userId() === $uid && Auth::role() === 'user', 'social identity and role preserved');
    $_SESSION=['user_id'=>$other, 'role'=>'user'];
    check(!$svc->handleLogin($provider, ['valid'=>true])['success'], 'foreign social binding rejected');
    check(Auth::userId() === $other, 'foreign binding leaves original identity');
    $tokenCountBefore = (int)$conn->query('SELECT COUNT(*) n FROM auth_tokens WHERE user_id=' . $uid)->fetch_assoc()['n'];
    $selector = $token($uid); Auth::tryRememberLogin();
    check(Auth::userId() === $uid && Auth::role() === 'user', 'remember succeeds while banned');
    check((int)$conn->query("SELECT COUNT(*) n FROM auth_tokens WHERE selector='$selector'")->fetch_assoc()['n'] === 0, 'successful remember rotates old token');
    check((int)$conn->query('SELECT COUNT(*) n FROM auth_tokens WHERE user_id=' . $uid)->fetch_assoc()['n'] === $tokenCountBefore + 1, 'remember replacement token exists');
    $token($uid, -60); Auth::tryRememberLogin(); check(!Auth::check(), 'expired remember rejected');
    $token($uid); $_COOKIE[Auth::REMEMBER_COOKIE] .= '-wrong'; Auth::tryRememberLogin(); check(!Auth::check(), 'wrong validator rejected');
    $_SESSION=[]; $_COOKIE[Auth::REMEMBER_COOKIE]='malformed'; Auth::tryRememberLogin(); check(!Auth::check(), 'malformed token rejected');
    $token(0); Auth::tryRememberLogin(); check(!Auth::check(), 'remember missing user rejected');
    $reject('fixture reason');
    $um->banUser($uid, 'timed reason', null, 15); $reject('МСК');
    $um->unbanUser($uid); $chat->assertCanSend($uid); check(true, 'unbanned guard permits send');
    Auth::login($login, 'test-only-password'); $um->banUser($uid, 'after login'); $reject('after login');
    $conn->query("UPDATE users SET ban_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id=$uid");
    $chat->assertCanSend($uid); check(true, 'expired ban permits send despite flag');
    $um->unbanUser($uid); $um->muteUser($uid, 10, null, 'muted reason'); $reject('muted reason');
    $conn->query("UPDATE users SET muted_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id=$uid");
    $chat->assertCanSend($uid); check(true, 'expired mute permits send');
    $um->unmuteUser($uid);
    $id=$chat->addMessage($uid, 'Fixture', 'Permitted fixture ' . $login);
    check(is_int($id) && $id>0, 'unbanned actual addMessage succeeds');
    // Проверяем orphan социальной связи без создания публичного endpoint.
    $domainConn = Database::getInstance()->getConnection();
    $domainConn->query("SET FOREIGN_KEY_CHECKS=0");
    try { $domainConn->query("UPDATE user_socials SET user_id=0 WHERE user_id=$uid"); }
    finally { $domainConn->query("SET FOREIGN_KEY_CHECKS=1"); }
    $_SESSION=[];
    check(!$svc->handleLogin($provider, ['valid'=>true])['success'], 'social missing user rejected');
} finally {
    $stmt = $conn->prepare('DELETE FROM user_socials WHERE provider = ? AND provider_uid = ?');
    $providerName='telegram'; $stmt->bind_param('ss', $providerName, $login); $stmt->execute();
    foreach (['auth_tokens','user_socials','chat_messages','user_options'] as $table) {
        $conn->query("DELETE FROM $table WHERE user_id IN ($uid,$other)");
    }
    $um->deleteUser($uid); $um->deleteUser($other); $_SESSION=[]; unset($_COOKIE[Auth::REMEMBER_COOKIE]);
}
ob_end_flush();
it_done();
