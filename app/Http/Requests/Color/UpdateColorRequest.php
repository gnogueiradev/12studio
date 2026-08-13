<?php

namespace App\Http\Requests\Color;

class UpdateColorRequest extends StoreColorRequest
{
    // Mesmas regras do store. A unicidade do nome ja ignora as linhas do
    // proprio grupo via StoreColorRequest::groupColorIds().
}
