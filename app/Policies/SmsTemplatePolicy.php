<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SmsTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

class SmsTemplatePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SmsTemplate');
    }

    public function view(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('View:SmsTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SmsTemplate');
    }

    public function update(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('Update:SmsTemplate');
    }

    public function delete(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('Delete:SmsTemplate');
    }

    public function restore(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('Restore:SmsTemplate');
    }

    public function forceDelete(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('ForceDelete:SmsTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SmsTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SmsTemplate');
    }

    public function replicate(AuthUser $authUser, SmsTemplate $smsTemplate): bool
    {
        return $authUser->can('Replicate:SmsTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SmsTemplate');
    }

}
