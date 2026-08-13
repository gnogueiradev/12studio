<?php

namespace App\Http\Requests\Color;

class UpdateColorRequest extends StoreColorRequest
{
    // Mesmas regras do store. A unicidade do nome ja ignora a propria cor via
    // StoreColorRequest::colorId(), que sai da rota.
}
