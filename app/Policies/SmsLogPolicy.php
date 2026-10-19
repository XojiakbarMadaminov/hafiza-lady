<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SmsLog;
use Illuminate\Auth\Access\HandlesAuthorization;

class SmsLogPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SmsLog');
    }

    public function view(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('View:SmsLog');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SmsLog');
    }

    public function update(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('Update:SmsLog');
    }

    public function delete(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('Delete:SmsLog');
    }

    public function restore(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('Restore:SmsLog');
    }

    public function forceDelete(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('ForceDelete:SmsLog');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SmsLog');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SmsLog');
    }

    public function replicate(AuthUser $authUser, SmsLog $smsLog): bool
    {
        return $authUser->can('Replicate:SmsLog');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SmsLog');
    }

}
