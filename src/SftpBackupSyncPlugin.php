<?php

namespace Lisak\SftpBackupSync;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class SftpBackupSyncPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'sftp-backup-sync';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->discoverPages(
                plugin_path($this->getId(), 'src', 'Filament', 'Server', 'Pages'),
                'Lisak\\SftpBackupSync\\Filament\\Server\\Pages',
            );
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('sftp-backup-sync');
    }

    /** @return \Filament\Schemas\Components\Component[] */
    public function getSettingsForm(): array
    {
        return [
            Section::make('OneDrive')
                ->description(
                    'Create an app registration at portal.azure.com -> App registrations, add a "Web" '
                    . 'redirect URI of ' . url('/plugin/sftp-backup-sync/onedrive/callback')
                    . ', grant the delegated Microsoft Graph permissions "Files.ReadWrite" and '
                    . '"offline_access", then paste the Application (client) ID and a client secret below.',
                )
                ->columns(2)
                ->schema([
                    TextInput::make('onedrive_client_id')
                        ->label('Client ID')
                        ->default(fn () => config('sftp-backup-sync.onedrive_client_id')),
                    TextInput::make('onedrive_client_secret')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText('Leave blank to keep the currently stored secret.'),
                ]),
            Section::make('Google Drive')
                ->description(
                    'Create an OAuth client at console.cloud.google.com -> APIs & Services -> Credentials '
                    . '(type "Web application"), add an authorized redirect URI of '
                    . url('/plugin/sftp-backup-sync/google/callback')
                    . ', enable the Google Drive API for the project, then paste the client ID and secret below.',
                )
                ->columns(2)
                ->schema([
                    TextInput::make('google_client_id')
                        ->label('Client ID')
                        ->default(fn () => config('sftp-backup-sync.google_client_id')),
                    TextInput::make('google_client_secret')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText('Leave blank to keep the currently stored secret.'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [];

        foreach (['onedrive_client_id', 'onedrive_client_secret', 'google_client_id', 'google_client_secret'] as $key) {
            if (filled($data[$key] ?? null)) {
                $values[Str::upper("SFTPBACKUPSYNC_{$key}")] = $data[$key];
            }
        }

        if ($values !== []) {
            $this->writeToEnvironment($values);
        }

        Notification::make()
            ->title(trans('admin/setting.save_success'))
            ->success()
            ->send();
    }
}
