<?php
declare(strict_types=1);
namespace Vereinskiste\Auth;
/** Central permission decision for actions and document resources. */
final class AccessControl
{
 public static function can(?array $user,string $permission,?array $resource=null): bool
 {
  if (!$user || ($user['active'] ?? true) === false || !in_array($permission,Permissions::ALL,true)) { return false; }
  $rights=Permissions::for($user['roles']);
  if (in_array('admin',$user['roles'],true)) { return true; }
  if ($permission==='members.write' && !empty($resource['login_email_change'])) { return in_array('users.manage',$rights,true); }
  if ($permission==='members.write' && !empty($resource['access_role'])) { return in_array('functions.manage',$rights,true); }
  if ($permission==='documents.read' && $resource!==null) {
   if (in_array('documents.confidential',$rights,true)) { return true; }
   return ($resource['status'] ?? null)==='published' && (in_array($resource['kind'] ?? null,['constitution','rules'],true)
    || (($resource['kind'] ?? null)==='minutes' && ($resource['meeting_type'] ?? null)==='assembly' && empty($resource['historic'])));
  }
  return in_array($permission,$rights,true);
 }
}
