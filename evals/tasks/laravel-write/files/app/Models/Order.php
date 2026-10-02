<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $customer_email
 * @property int $amount_cents
 * @property string $status pending, paid or payment_failed
 * @property string|null $charge_id
 */
class Order extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];
}
