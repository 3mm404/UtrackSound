<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
TextInput::make('name')
    ->required(),

TextInput::make('email')
    ->label('Email address')
    ->email()
    ->required(),

Select::make('role_id')
    ->label('Rol')
    ->relationship('role', 'name')
    ->required()
    ->preload(),

Select::make('businesses')
    ->label('Negocios')
    ->relationship('businesses', 'name')
    ->multiple()
    ->preload()
    ->searchable(),
            ]);
    }
}