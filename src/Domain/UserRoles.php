<?php
namespace Domain;

use Core\UserError;

/** Additive memberships; the scalar role is retained only as a compatibility projection. */
final class UserRoles
{
    public const ALL = ['user','moderator','admin','streamer'];
    public const LABELS = ['user'=>'Пользователь','moderator'=>'Модератор','admin'=>'Администратор','streamer'=>'Стример'];

    public static function normalize(mixed $input, bool $strict=true): array
    {
        if(is_string($input))$input=[$input];
        if(!is_array($input)||!array_is_list($input)||count($input)>16){
            if($strict)throw new UserError('Некорректный список ролей.');
            return ['user'];
        }
        $found=['user'=>true];
        foreach($input as $role){
            if(!is_string($role)||!in_array($role,self::ALL,true)){
                if($strict)throw new UserError('Неизвестная роль пользователя.');
                continue;
            }
            $found[$role]=true;
        }
        return array_values(array_filter(self::ALL,static fn($role)=>isset($found[$role])));
    }

    public static function fromUser(array $user): array
    {
        if(array_key_exists('roles',$user))return is_array($user['roles'])?self::normalize($user['roles'],false):['user'];
        if(isset($user['roles_json'])){
            $decoded=is_string($user['roles_json'])?json_decode($user['roles_json']):$user['roles_json'];
            return is_array($decoded)?self::normalize($decoded,false):['user'];
        }
        return self::normalize([$user['role']??'user'],false);
    }

    public static function has(array $user,string $role): bool { return in_array($role,self::fromUser($user),true); }
    public static function primary(array $roles): string
    {
        if(in_array('admin',$roles,true))return 'admin';
        return in_array('moderator',$roles,true)?'moderator':'user';
    }
    public static function decorate(?array $user): ?array
    {
        if($user===null)return null;
        $user['roles']=self::fromUser($user);$user['role']=self::primary($user['roles']);unset($user['roles_json']);return $user;
    }
}
