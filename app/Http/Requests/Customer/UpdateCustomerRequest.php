<?php

namespace App\Http\Requests\Customer;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    // Mesmas regras do store; a unicidade do email ja ignora o proprio
    // cliente via StoreCustomerRequest::customerId(). A password nunca e
    // editavel pelo admin — o cliente define a sua por recuperacao.
}
