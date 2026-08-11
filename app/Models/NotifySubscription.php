<?php

namespace App\Models;

use Database\Factories\NotifySubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Email deixado na landing "em breve" para ser avisado na abertura da loja.
 *
 * @property int $id
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email'])]
class NotifySubscription extends Model
{
    /** @use HasFactory<NotifySubscriptionFactory> */
    use HasFactory;
}
