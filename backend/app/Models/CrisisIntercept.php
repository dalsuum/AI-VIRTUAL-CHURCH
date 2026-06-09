<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrisisIntercept extends Model
{
    protected $fillable = ['session_hash', 'trigger_keyword', 'resource_served'];
}
