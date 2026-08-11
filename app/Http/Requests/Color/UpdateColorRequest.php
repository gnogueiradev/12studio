<?php

namespace App\Http\Requests\Color;

class UpdateColorRequest extends StoreColorRequest
{
    // Mesmas regras do store. A unicidade dentro do material ja ignora a
    // propria cor via StoreColorRequest::colorId().
}
