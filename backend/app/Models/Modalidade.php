<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modalidade extends Model
{
    protected $table = 'classificacoes_modalidades';

    protected $guarded = ['id'];
}
