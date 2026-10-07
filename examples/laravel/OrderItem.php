<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A line of an order with what the fiscal receipt needs: the MXIK (IKPU) code, the package code, the VAT rate.
 */
class OrderItem extends Model
{
    protected $fillable = ['order_id', 'title', 'price', 'quantity', 'mxik_code', 'package_code', 'vat_percent'];
}
