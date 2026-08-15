<?php

namespace Lisak\SftpBackupSync\Filament\Server\Pages;

use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Backup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Lisak\SftpBackupSync\Jobs\PushBackupToSftp;
use Lisak\SftpBackupSync\Models\BackupSyncLog;
use Lisak\SftpBackupSync\Models\SftpBackupTarget;
use Lisak\SftpBackupSync\Support\OAuth\CloudOAuthProviderFactory;

class BackupSync extends ServerFormPage
{
    protected static ?string $slug = 'backup-sync';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-cloud-upload';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return parent::canAccess() && user()?->can('backup_sync.update', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('backup_sync.update', $this->getRecord()), 403);
    }

    public function mount(): void
    {
        parent::mount();

        if ($message = session('sftp-backup-sync-success')) {
            Notification::make()->title($message)->success()->send();
        }

        if ($message = session('sftp-backup-sync-error')) {
            Notification::make()->title($message)->danger()->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->components([
                Section::make('Backup Sync')
                    ->description('Automatically copy every completed backup of this server to your own destination — nobody else on this panel can see or use this configuration.')
                    ->columnSpanFull()
                    ->footerActions([
                        $this->syncMissingAction(),
                        $this->connectAction(),
                        $this->disconnectAction(),
                        Action::make('save')
                            ->label('Save')
                            ->action('saveTarget'),
                    ])
                    ->footerActionsAlignment(Alignment::Right)
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Forward backups')
                            ->columnSpanFull(),

                        Select::make('protocol')
                            ->options([
                                'sftp' => 'SFTP',
                                'webdav' => 'WebDAV (e.g. Nextcloud)',
                                'onedrive' => 'OneDrive',
                                'google_drive' => 'Google Drive',
                            ])
                            ->default('sftp')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        Fieldset::make('SFTP connection')
                            ->visible(fn (Get $get) => $get('protocol') === 'sftp')
                            ->columnSpanFull()
                            ->columns(3)
                            ->schema([
                                TextInput::make('host')
                                    ->required(fn (Get $get) => $get('protocol') === 'sftp')
                                    ->columnSpan(2),
                                TextInput::make('port')
                                    ->numeric()
                                    ->default(22)
                                    ->columnSpan(1),
                                Select::make('auth_method')
                                    ->label('Authentication')
                                    ->options([
                                        'password' => 'Password',
                                        'private_key' => 'Private key',
                                    ])
                                    ->default('password')
                                    ->live()
                                    ->columnSpanFull(),
                                Textarea::make('private_key')
                                    ->rows(6)
                                    ->dehydrated(fn (?string $state) => filled($state))
                                    ->helperText('Leave blank to keep the currently stored key.')
                                    ->visible(fn (Get $get) => $get('protocol') === 'sftp' && $get('auth_method') === 'private_key')
                                    ->columnSpanFull(),
                                TextInput::make('passphrase')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn (?string $state) => filled($state))
                                    ->helperText('Only needed if the private key itself is encrypted. Leave blank to keep the current one.')
                                    ->visible(fn (Get $get) => $get('protocol') === 'sftp' && $get('auth_method') === 'private_key')
                                    ->columnSpanFull(),
                            ]),

                        Fieldset::make('WebDAV connection')
                            ->visible(fn (Get $get) => $get('protocol') === 'webdav')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('base_url')
                                    ->label('Server URL')
                                    ->placeholder('https://cloud.example.com/remote.php/dav/files/username')
                                    ->url()
                                    ->required(fn (Get $get) => $get('protocol') === 'webdav')
                                    ->columnSpanFull(),
                            ]),

                        Fieldset::make('Cloud account')
                            ->visible(fn (Get $get) => in_array($get('protocol'), CloudOAuthProviderFactory::oauthProtocols(), true))
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('oauth_status')
                                    ->hiddenLabel()
                                    ->state(fn () => $this->oauthStatusText())
                                    ->columnSpanFull(),
                            ]),

                        TextInput::make('username')
                            ->visible(fn (Get $get) => in_array($get('protocol'), ['sftp', 'webdav'], true))
                            ->columnSpan(1),

                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText('Leave blank to keep the currently stored password.')
                            ->visible(fn (Get $get) => $get('protocol') === 'webdav'
                                || ($get('protocol') === 'sftp' && $get('auth_method') === 'password'))
                            ->columnSpan(1),

                        TextInput::make('remote_path')
                            ->label('Remote directory')
                            ->helperText('For OneDrive/Google Drive this is a folder name (created automatically if missing).')
                            ->default('/')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function fillForm(): void
    {
        $target = SftpBackupTarget::query()->where('server_id', $this->getRecord()->id)->first();

        $this->form->fill([
            'enabled' => $target?->enabled ?? false,
            'protocol' => $target?->protocol ?? 'sftp',
            'host' => $target?->host,
            'port' => $target?->port ?? 22,
            'base_url' => $target?->base_url,
            'username' => $target?->username,
            'auth_method' => $target?->auth_method ?? 'password',
            'remote_path' => $target?->remote_path ?? '/',
            // password / private_key / passphrase are intentionally left out: an
            // encrypted secret is never sent back down to the browser, and a blank
            // field means "keep what's already stored" (see saveTarget()).
        ]);
    }

    public function saveTarget(): void
    {
        abort_unless(user()?->can('backup_sync.update', $this->getRecord()), 403);

        $state = $this->form->getState();

        $payload = [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'protocol' => $state['protocol'] ?? 'sftp',
            'host' => $state['host'] ?? null,
            'port' => $state['port'] ?? 22,
            'base_url' => $state['base_url'] ?? null,
            'username' => $state['username'] ?? null,
            'auth_method' => $state['auth_method'] ?? 'password',
            'remote_path' => filled($state['remote_path'] ?? null) ? $state['remote_path'] : '/',
        ];

        foreach (['password', 'private_key', 'passphrase'] as $secretField) {
            if (filled($state[$secretField] ?? null)) {
                $payload[$secretField] = $state[$secretField];
            }
        }

        SftpBackupTarget::query()->updateOrCreate(
            ['server_id' => $this->getRecord()->id],
            $payload,
        );

        Notification::make()
            ->title('Backup sync settings saved.')
            ->success()
            ->send();
    }

    protected function syncMissingAction(): Action
    {
        return Action::make('sync_missing')
            ->label('Sync missing backups now')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Queue every successful backup of this server that has not been synced yet.')
            ->visible(fn () => (bool) optional($this->currentTarget())->enabled)
            ->action(function () {
                $server = $this->getRecord();
                $target = $this->currentTarget();

                if (!$target || !$target->enabled) {
                    return;
                }

                $syncedBackupIds = BackupSyncLog::query()
                    ->where('sftp_backup_target_id', $target->id)
                    ->where('status', 'success')
                    ->pluck('backup_id');

                $pending = Backup::query()
                    ->where('server_id', $server->id)
                    ->where('is_successful', true)
                    ->whereNotIn('id', $syncedBackupIds)
                    ->get();

                foreach ($pending as $backup) {
                    PushBackupToSftp::dispatch($backup);
                }

                Notification::make()
                    ->title($pending->count() > 0
                        ? "Queued {$pending->count()} backup(s) for sync."
                        : 'Nothing to sync — everything is already up to date.')
                    ->success()
                    ->send();
            });
    }

    protected function connectAction(): Action
    {
        return Action::make('connect_oauth')
            ->label(fn (Get $get) => 'Connect ' . $this->oauthProviderLabel($get('protocol')))
            ->color('gray')
            ->visible(fn (Get $get) => in_array($get('protocol'), CloudOAuthProviderFactory::oauthProtocols(), true)
                && !$this->currentTarget()?->oauth_access_token)
            ->url(fn (Get $get) => route('sftp-backup-sync.connect', [
                'protocol' => $get('protocol'),
                'server' => $this->getRecord(),
            ]))
            ->openUrlInNewTab(false);
    }

    protected function disconnectAction(): Action
    {
        return Action::make('disconnect_oauth')
            ->label('Disconnect')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Get $get) => in_array($get('protocol'), CloudOAuthProviderFactory::oauthProtocols(), true)
                && (bool) $this->currentTarget()?->oauth_access_token)
            ->action(function () {
                $this->currentTarget()?->update([
                    'oauth_access_token' => null,
                    'oauth_refresh_token' => null,
                    'oauth_expires_at' => null,
                    'oauth_account_label' => null,
                ]);

                Notification::make()->title('Disconnected.')->success()->send();
            });
    }

    private function oauthStatusText(): string
    {
        $target = $this->currentTarget();

        if ($target?->oauth_access_token) {
            return 'Connected' . ($target->oauth_account_label ? " as {$target->oauth_account_label}." : '.');
        }

        return 'Not connected yet — click "Connect" below, then Save once you\'re back here.';
    }

    private function oauthProviderLabel(?string $protocol): string
    {
        return match ($protocol) {
            'onedrive' => 'OneDrive',
            'google_drive' => 'Google Drive',
            default => 'account',
        };
    }

    private function currentTarget(): ?SftpBackupTarget
    {
        return SftpBackupTarget::query()->where('server_id', $this->getRecord()->id)->first();
    }

    public function getTitle(): string
    {
        return 'Backup Sync';
    }

    public static function getNavigationLabel(): string
    {
        return 'Backup Sync';
    }
}
