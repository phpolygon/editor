<?php

namespace App\Providers;

use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;
use Native\Desktop\Contracts\ProvidesPhpIni;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Menu::create(
            Menu::app(),
            Menu::make(
                Menu::link('/', 'Open Project...', 'CmdOrCtrl+O'),
            )->label('File'),
            Menu::make(
                Menu::undo(),
                Menu::redo(),
                Menu::separator(),
                Menu::cut(),
                Menu::copy(),
                Menu::paste(),
            )->label('Edit'),
            Menu::window(),
        );

        Window::open()
            ->title('PHPolygon Editor')
            ->width(1400)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(768);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
