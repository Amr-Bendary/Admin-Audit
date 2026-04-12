<?php

namespace Bendary\AdminAudit\Listeners;

use Bendary\AdminAudit\AuditLog;
use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Settings\Event\Saving;
use Flarum\User\Event\Saved as UserSaved;
use Flarum\User\Event\Created as UserCreated;
use Flarum\User\Event\Deleted as UserDeleted;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;

class AuditLogEvents
{
    public function subscribe(Dispatcher $events)
    {
        $events->listen(Saving::class, [$this, 'whenSettingsSaved']);
        $events->listen(Enabled::class, [$this, 'whenExtensionEnabled']);
        $events->listen(Disabled::class, [$this, 'whenExtensionDisabled']);

        $events->listen(UserSaved::class, [$this, 'whenUserSaved']);
        $events->listen(UserCreated::class, [$this, 'whenUserCreated']);
        $events->listen(UserDeleted::class, [$this, 'whenUserDeleted']);
        $events->listen(GroupsChanged::class, [$this, 'whenGroupsChanged']);
    }

    public function whenSettingsSaved(Saving $event)
    {
        $settings = $event->settings;
        $keys = array_keys($settings);
        
        // Categorize the specific setting change
        $types = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, 'theme_') || str_starts_with($key, 'custom_')) {
                $types[] = 'Appearance Settings';
            } elseif (str_starts_with($key, 'mail_')) {
                $types[] = 'Mail Settings';
            } elseif (str_starts_with($key, 'forum_') || $key === 'default_locale') {
                $types[] = 'Basic Settings';
            } elseif (str_contains($key, '.')) {
                $prefix = explode('.', $key)[0];
                // Flarum extensions often use flarum-tags.something layout
                $types[] = "Extension ($prefix) Settings";
            } else {
                $types[] = 'Settings';
            }
        }
        $types = array_unique($types);
        $targetDesc = count($types) > 0 ? implode(', ', $types) : 'Settings Updated';

        $actorId = $this->getCurrentUserId();

        $audit = AuditLog::build(
            $actorId,
            'settings',
            'update_settings',
            $targetDesc, 
            null, 
            $settings, 
            null,
            $this->getIpAddress()
        );
        $audit->save();
    }

    public function whenExtensionEnabled(Enabled $event)
    {
        $extension = $event->extension;
        $audit = AuditLog::build(
            $this->getCurrentUserId(),
            'extensions',
            'enable_extension',
            $extension->getId(),
            null,
            null,
            [
                'version' => $extension->getVersion(),
                'name' => $extension->name,
                'title' => $extension->composerJsonAttribute('extra.flarum-extension.title')
            ],
            $this->getIpAddress()
        );
        $audit->save();
    }

    public function whenExtensionDisabled(Disabled $event)
    {
        $extension = $event->extension;
        $audit = AuditLog::build(
            $this->getCurrentUserId(),
            'extensions',
            'disable_extension',
            $extension->getId(),
            null,
            null,
            [
                'version' => $extension->getVersion(),
                'name' => $extension->name,
                'title' => $extension->composerJsonAttribute('extra.flarum-extension.title')
            ],
            $this->getIpAddress()
        );
        $audit->save();
    }

    public function whenUserSaved(UserSaved $event)
    {
        $this->logUserAction('update_user', $event->user, $event->actor, $event->data);
    }

    public function whenUserCreated(UserCreated $event)
    {
        $this->logUserAction('create_user', $event->user, $event->actor, $event->data);
    }

    public function whenUserDeleted(UserDeleted $event)
    {
        $this->logUserAction('delete_user', $event->user, $event->actor ?? null, []);
    }

    public function whenGroupsChanged(GroupsChanged $event)
    {
        $this->logUserAction('groups_changed', $event->user, $event->actor, ['groups' => $event->user->groups()->pluck('name', 'id')->all()]);
    }

    protected function logUserAction($actionName, $user, $eventActor, $data)
    {
        $actor = $this->getBestActor($eventActor);

        if (!$actor || !$actor->isAdmin()) {
            return;
        }

        $safeData = $data;
        if (isset($safeData['attributes']['password'])) {
            $safeData['attributes']['password'] = '***';
        }

        $targetDesc = "User: " . ($user->display_name ?: $user->username) . " (ID: {$user->id})";

        $audit = AuditLog::build(
            $actor->id,
            'users',
            $actionName,
            $targetDesc,
            null,
            $safeData,
            null,
            $this->getIpAddress()
        );
        $audit->save();
    }

    protected function getBestActor($eventActor = null)
    {
        // 1. Try actor passed by the event
        if ($eventActor && $eventActor instanceof User && !$eventActor->isGuest()) {
            return $eventActor;
        }

        // 2. Try our bound request context
        if (app()->bound('audit.current_request')) {
            try {
                $request = app()->make('audit.current_request');
                $actor = \Flarum\Http\RequestUtil::getActor($request);
                if ($actor && $actor instanceof User && !$actor->isGuest()) {
                    return $actor;
                }
            } catch (\Exception $e) {}
        }

        // 3. Try Flarum's global actor if available
        if (app()->bound('flarum.actor')) {
            $actor = app()->make('flarum.actor');
            if ($actor && $actor instanceof User && !$actor->isGuest()) {
                return $actor;
            }
        }

        return null;
    }

    protected function getCurrentUserId()
    {
        $actor = $this->getBestActor();
        return $actor ? $actor->id : null;
    }

    protected function getIpAddress()
    {
        if (app()->bound('audit.current_request')) {
            try {
                $request = app()->make('audit.current_request');
                return Arr::get($request->getServerParams(), 'REMOTE_ADDR');
            } catch (\Exception $e) {
                // Ignore exception fallback
            }
        }

        return null;
    }
}
