<?php

namespace Lisak\SftpBackupSync\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Lisak\SftpBackupSync\Filament\Server\Pages\BackupSync;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProviderFactory;
use RuntimeException;
use Throwable;

class OAuthConnectController extends Controller
{
    private const SESSION_KEY = 'sftp-backup-sync.oauth-connect';

    /**
     * Step 1: the user clicked "Connect" on the Backup Sync page. Stash which
     * server/protocol/user this is for in the session (never trust anything
     * the redirect_uri round-trip could hand back to us instead), then send
     * the browser to the provider's real login screen.
     */
    public function connect(Request $request, string $protocol, Server $server): RedirectResponse
    {
        abort_unless(in_array($protocol, CloudOAuthProviderFactory::oauthProtocols(), true), 404);
        abort_unless($request->user()?->can('backup_sync.update', $server), 403);

        $provider = CloudOAuthProviderFactory::make($protocol);
        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'protocol' => $protocol,
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
            'state' => $state,
        ]);

        return redirect()->away($provider->getAuthorizeUrl($this->redirectUri($protocol), $state));
    }

    /**
     * Step 2: the provider redirected back with a `code` (or an error/denial).
     * The intent stashed in step 1 is the source of truth for "which server
     * is this" -- the `state` param is only used to confirm this callback
     * belongs to the request we just started (CSRF), it never carries data.
     */
    public function callback(Request $request, string $protocol): RedirectResponse
    {
        abort_unless(in_array($protocol, CloudOAuthProviderFactory::oauthProtocols(), true), 404);

        $intent = $request->session()->pull(self::SESSION_KEY);

        $stateMatches = $intent
            && hash_equals((string) $intent['state'], (string) $request->query('state', ''));

        abort_unless(
            $stateMatches
                && $intent['protocol'] === $protocol
                && $intent['user_id'] === $request->user()?->id,
            403,
            'This connection request is invalid or has expired -- please try connecting again.',
        );

        $server = Server::findOrFail($intent['server_id']);
        abort_unless($request->user()?->can('backup_sync.update', $server), 403);

        $redirectBack = BackupSync::getUrl(panel: 'server', tenant: $server);

        $code = $request->query('code');
        if (!$code) {
            return redirect($redirectBack)->with('sftp-backup-sync-error', 'Connection was cancelled or failed.');
        }

        $provider = CloudOAuthProviderFactory::make($protocol);

        try {
            $tokens = $provider->exchangeCode($code, $this->redirectUri($protocol));

            throw_unless(
                $tokens['refresh_token'],
                new RuntimeException('The provider did not return a refresh token. Try disconnecting and connecting again.'),
            );

            $accountLabel = $provider->fetchAccountLabel($tokens['access_token']);

            SftpBackupTarget::query()->updateOrCreate(
                ['server_id' => $server->id],
                [
                    'protocol' => $protocol,
                    'oauth_access_token' => $tokens['access_token'],
                    'oauth_refresh_token' => $tokens['refresh_token'],
                    'oauth_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'oauth_account_label' => $accountLabel,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect($redirectBack)->with('sftp-backup-sync-error', 'Could not connect: ' . $exception->getMessage());
        }

        return redirect($redirectBack)->with(
            'sftp-backup-sync-success',
            'Connected' . ($accountLabel ? " as {$accountLabel}." : '.'),
        );
    }

    private function redirectUri(string $protocol): string
    {
        return route('sftp-backup-sync.callback', ['protocol' => $protocol]);
    }
}
