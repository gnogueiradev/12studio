<?php

namespace App\Http\Requests\Printer;

/**
 * Mesmas regras do store. A unicidade do nome ja ignora a propria impressora
 * via StorePrinterProfileRequest::profileId(); nao ha campos so-de-criacao a
 * excluir, ao contrario do material e das suas cores iniciais.
 */
class UpdatePrinterProfileRequest extends StorePrinterProfileRequest {}
