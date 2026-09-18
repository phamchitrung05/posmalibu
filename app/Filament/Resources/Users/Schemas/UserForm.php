<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserForm
{
    /** Khai báo form tài khoản nhân viên và chi nhánh phụ trách. */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Không nhận store_id từ form. Filament tự gán Store đang chọn
                // trên URL, nhờ đó owner không thể vô tình tạo user sai tenant.
                TextInput::make('name')->label('Họ và tên')->required()->maxLength(255),
                TextInput::make('email')->label('Email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->label('Mật khẩu')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
                // Owner được phép tạo tài khoản owner khác; chỉ bị khóa vai trò
                // khi sửa chính tài khoản đang đăng nhập để không tự hạ quyền.
                Select::make('roles')
                    ->label('Vai trò')
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn('name', [UserRole::Owner->value, UserRole::Staff->value]),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record->getAttribute('name') === UserRole::Owner->value ? 'Quản trị viên' : 'Nhân viên')
                    ->multiple()
                    ->maxItems(1)
                    ->preload()
                    ->required()
                    ->disabled(fn (?Model $record): bool => $record !== null && $record->getKey() === auth()->id())
                    ->dehydrated(fn (?Model $record): bool => ! ($record !== null && $record->getKey() === auth()->id())),
            ]);
    }
}
