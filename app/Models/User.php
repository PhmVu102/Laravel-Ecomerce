<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $fillable = [
        'name', 'email', 'password',
        'phone', 'role', 'status', 'avatar' // Thêm các cột mới vào đây
    ];

    // Một user có nhiều đơn hàng
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    //Giỏ hàng
    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    // Một user có nhiều yêu cầu đổi trả
    public function returnRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    // Một user có nhiều cuộc trò chuyện (AI chat)
    public function conversations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
