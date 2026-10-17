<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OwnerResource\Pages\ListOwners;
use App\Filament\Resources\OwnerResource\Pages\ViewOwner;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends resource<User> */
class OwnerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'owners';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name'), TextColumn::make('email')])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('name'), TextEntry::make('email')]);
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $owner = auth()->user();

        return $owner instanceof User ? $query->ownedBy($owner) : $query->whereRaw('1 = 0');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListOwners::route('/'), 'view' => ViewOwner::route('/{record}')];
    }
}
