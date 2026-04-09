<?php

namespace Bendary\AdminAudit\Listeners;

use Bendary\AdminAudit\AuditLog;
use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Settings\Event\Saved;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;

class AuditLogEvents
{
    public function subscribe(Dispatcher $events)
    {
        $events->listen(Saved::class, [$this, 'whenSettingsSaved']);
        $events->listen(Enabled::class, [$this, 'whenExtensionEnabled']);
        $events->listen(Disabled::class, [$this, 'whenExtensionDisabled']);
    }

    public function whenSettingsSaved(Saved $event)
    {
        // Settings are saved in batches usually
        $settings = $event->settings;
        
        // We might not have actor in the event directly, 
        // but typically, settings saved from admin panel happens via API 
        // We can capture the current actor via container or check if we can get it.
        // For simplicity, let's just log "Settings Updated".
        // In a real robust scenario, we extract Request from IoC container if needed.
        
        $actorId = $this->getCurrentUserId();

        $audit = AuditLog::build(
            $actorId,
            'settings',
            'update_settings',
            'Multiple Settings', // Or we could join keys
            null, // Could fetch old values if needed, but not provided by this event out of box easily
            $settings, // new values
            null,
            $this->getIpAddress()
        );
        $audit->save();
    }

    public function whenExtensionEnabled(Enabled $event)
    {
        $audit = AuditLog::build(
            $this->getCurrentUserId(),
            'extensions',
            'enable_extension',
            $event->extension->name,
            null,
            null,
            ['version' => $event->extension->getVersion()],
            $this->getIpAddress()
        );
        $audit->save();
    }

    public function whenExtensionDisabled(Disabled $event)
    {
        $audit = AuditLog::build(
            $this->getCurrentUserId(),
            'extensions',
            'disable_extension',
            $event->extension->name,
            null,
            null,
            ['version' => $event->extension->getVersion()],
            $this->getIpAddress()
        );
        $audit->save();
    }

    protected function getCurrentUserId()
    {
        // Fallback or via DI, since Flarum events don't always pass the actor.
        // Normally, for admin actions, the API request holds the actor.
        // We can get it from the request if we inject ServerRequestInterface,
        // but since this is a listener, we might be out of request scope sometimes.
        // By looking up the container:
        try {
            $request = resolve(\Psr\Http\Message\ServerRequestInterface::class);
            $actor = $request->getAttribute('actor');
            if ($actor && $actor instanceof User && !$actor->isGuest()) {
                return $actor->id;
            }
        } catch (\Exception $e) {
            // Ignored
        }

        return null; // System
    }

    protected function getIpAddress()
    {
        try {
            $request = resolve(\Psr\Http\Message\ServerRequestInterface::class);
            $serverParams = $request->getServerParams();
            return Arr::get($serverParams, 'REMOTE_ADDR');
        } catch (\Exception $e) {
            // Ignored
        }

        return null;
    }
}
