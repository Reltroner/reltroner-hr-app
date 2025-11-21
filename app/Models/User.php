<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * App\Models\User
 *
 * Uraian singkat:
 * - Relasi ke Employee melalui kolom employee_id (belongsTo).
 * - Mendukung legacy 'role' column dan relasi employee->role->title.
 * - Jika proyek menggunakan Spatie/HasRoles, uncomment trait HasRoles.
 */
class User extends Authenticatable // implement MustVerifyEmail if you require email verification
{
    use HasFactory, Notifiable;
    // If you're using Spatie Roles & Permissions package, uncomment:
    // use \Spatie\Permission\Traits\HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * Tambahkan 'employee_id' jika users.employee_id ada di DB.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id', // hubungan ke employees.id (opsional tetapi berguna)
        'role', // optional: legacy single role string column
    ];

    /**
     * The attributes that should be hidden for arrays / serialization.
     *
     * @var array<int,string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * Note: 'password' => 'hashed' tersedia di Laravel 10+ untuk auto-hash saat set.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Additional attributes to append when serializing the model.
     *
     * Example: $user->display_name will be available in arrays / JSON.
     *
     * @var array<int,string>
     */
    protected $appends = [
        'display_name',
    ];

    /**
     * Relationship: User belongsTo Employee
     *
     * Struktur project menunjukkan users.employee_id -> employees.id,
     * sehingga relasi yang benar adalah belongsTo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    /**
     * Convenience accessor: display_name
     * Prefer `fullname` from employee if available, otherwise use user->name.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $fullname = optional($this->employee)->fullname;
        return (string) ($fullname ?: $this->name);
    }

    /**
     * Return the user's "effective" role name.
     *
     * Priority:
     * 1. Session('role') if set (used by some controllers/middlewares)
     * 2. employee->role->title (if Employee has relation to Role model)
     * 3. user->role (legacy column)
     * 4. null if none found
     *
     * @return string|null
     */
    public function effectiveRole(): ?string
    {
        $roleFromSession = session('role') ?? null;

        if (!empty($roleFromSession)) {
            return (string) $roleFromSession;
        }

        $roleFromEmployee = optional(optional($this->employee)->role)->title
            ?? optional($this->employee)->role ?? null;

        if (!empty($roleFromEmployee)) {
            return (string) $roleFromEmployee;
        }

        return $this->role ?? null;
    }

    /**
     * Quick-check if user has a specific role name (case-insensitive).
     *
     * This helper DOES NOT replace Spatie's hasRole when using the package,
     * but will still call hasRole() if the method exists (Spatie).
     *
     * @param  string|array  $role
     * @return bool
     */
    public function hasRoleName($role): bool
    {
        // If Spatie is installed, prefer its method
        if (method_exists($this, 'hasRole')) {
            // Spatie handles arrays/strings itself
            return $this->hasRole($role);
        }

        $effective = $this->effectiveRole();
        if ($effective === null) {
            return false;
        }

        $allowed = is_array($role) ? $role : array_map('trim', explode(',', (string) $role));
        $allowedNormalized = array_map(fn($r) => Str::lower((string) $r), $allowed);
        return in_array(Str::lower((string) $effective), $allowedNormalized, true);
    }

    /**
     * Convenience: assign legacy role string to user (for seeding/dev).
     * If you use Spatie, prefer $user->assignRole('Admin').
     *
     * @param string $role
     * @return $this
     */
    public function setLegacyRole(string $role)
    {
        $this->role = $role;
        $this->save();
        return $this;
    }

    /**
     * Example scope: users with a non-null employee_id
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithEmployee($query)
    {
        return $query->whereNotNull('employee_id');
    }
}
