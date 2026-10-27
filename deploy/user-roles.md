# Multiple user roles

`users.roles_json` stores the complete role set. `user` is mandatory; optional memberships are `moderator`, `admin`, and `streamer`. A database check rejects role arrays without `user`. Nullable rows from an older running process remain readable through their original scalar role.

`users.role` is a compatibility projection: `admin`, otherwise `moderator`, otherwise `user`. It does not represent the complete membership. New checks use `Domain\UserRoles::has()` or `Domain\Auth::hasRole()`. Existing staff permissions are preserved; streamer membership does not imply administrator or moderator authority. Administrator membership does not imply streamer identity or private stream notifications.

Create accounts and change roles through `UserManager`; do not update the scalar directly. For example:

```php
$users->updateUser($userId, ['roles' => ['user', 'moderator', 'streamer']]);
```

The manager normalizes memberships, updates the array and compatibility projection atomically, and revokes streaming access when necessary. HTTP administrative writes also pass the authenticated administrator ID for a locking permission recheck. Legacy scalar API updates preserve streamer membership; unchanged legacy values preserve the complete existing set. A profile update cannot modify roles. Authentication, social login and remember-me carry the full set; HTTP boundaries refresh it without requiring another login.

Removing `streamer` disconnects its notification contact, invalidates pending links, closes related incidents and disables monitoring for affected event/default assignments. Events and playlist history are retained. Reassign responsibility explicitly when restoring monitoring. Users without streamer membership do not see the notification section in their profiles. A user may still disconnect their own obsolete contact; this does not grant notification access.

## Deployment

Apply `migrations/2026_10_27_multiple_user_roles.sql` before deploying code. It adds and backfills the JSON column, retains every existing scalar value and establishes the mandatory baseline check. Back up the existing user IDs/roles and schema outside the web root before applying it. Restart the `mlp-stream-monitor` and `mlp-lyra-bot` application services after deployment; do not restart MediaMTX for this change.

Grant streamer membership explicitly through the existing user card. It is not automatically assigned to administrators. An existing private bot conversation may be reused only after explicit user authorization and verification that its numerical Telegram ID matches the site's current social binding and the configured bot. Ordinary users connect through the profile's single-use link.

Dashboard access remains administrator-only in this release. Player settings, stream-key access and ownership-based schedule administration for streamers belong to the later permission-separation stage.
