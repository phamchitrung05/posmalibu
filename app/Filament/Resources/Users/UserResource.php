<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    /**
     * Danh sách tài khoản là dữ liệu quản trị toàn cục, không giới hạn theo
     * Store trên URL. UserPolicy vẫn chỉ cấp quyền cho owner nên staff không
     * thể xem danh sách này; owner sẽ thấy cả owner và staff của mọi chi nhánh.
     */
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Tài khoản';

    protected static ?string $pluralModelLabel = 'Tài khoản';

    protected static ?string $navigationLabel = 'Tài khoản';

    protected static string|\UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
