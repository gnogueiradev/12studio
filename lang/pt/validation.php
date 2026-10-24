<?php

/*
| Mensagens de validacao em portugues de Portugal. So as regras que os
| formularios usam: o Laravel junta este ficheiro com o seu (em ingles), por
| isso uma regra que falte aqui continua a ter mensagem.
*/

return [
    'accepted' => 'Tens de aceitar :attribute.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'date' => 'O campo :attribute não é uma data válida.',
    'after_or_equal' => 'O campo :attribute tem de ser uma data igual ou posterior a :date.',
    'email' => 'O campo :attribute tem de ser um email válido.',
    'extensions' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'file' => 'O campo :attribute tem de ser um ficheiro.',
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor escolhido em :attribute não é válido.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais de :max elementos.',
        'file' => 'O ficheiro :attribute não pode ter mais de :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior do que :max.',
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
    ],
    'mimes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min elementos.',
        'file' => 'O ficheiro :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute tem de ser pelo menos :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'numeric' => 'O campo :attribute tem de ser um número.',
    'regex' => 'O formato de :attribute não é válido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'string' => 'O campo :attribute tem de ser texto.',
    'uploaded' => 'Não foi possível carregar :attribute.',
    'url' => 'O campo :attribute tem de ser um endereço válido.',

    'attributes' => [
        'name' => 'nome',
        'email' => 'email',
        'phone' => 'telefone',
        'message' => 'mensagem',
        'quantity' => 'quantidade',
        'consent' => 'a política de privacidade',
        'files' => 'ficheiros',
    ],
];
