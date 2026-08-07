<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ConfigCacheSafetyTest extends TestCase
{
    /**
     * Teste-guarda de arquitetura (padrao qrcode): env() SO pode ser chamado
     * dentro de config/. O deploy corre `artisan optimize` (config cache +
     * opcache com validate_timestamps=0) — depois disso, env() fora de
     * config/ devolve null em producao e falha de forma silenciosa.
     */
    public function test_env_is_only_called_inside_config(): void
    {
        $roots = ['app', 'bootstrap', 'database', 'routes'];
        $offenders = [];

        foreach ($roots as $root) {
            $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$root;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // bootstrap/cache contem ficheiros gerados — fora do ambito.
                if (str_contains($file->getPathname(), 'bootstrap'.DIRECTORY_SEPARATOR.'cache')) {
                    continue;
                }

                if ($this->fileCallsEnv($file->getPathname())) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env() chamado fora de config/ — le o valor via config() com default:\n".implode("\n", $offenders),
        );
    }

    private function fileCallsEnv(string $path): bool
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
                continue;
            }

            // Metodos/propriedades chamados "env" (->env, ::env) nao contam.
            $previous = $this->previousMeaningfulToken($tokens, $i);
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            // So conta se for uma chamada: o proximo token relevante e "(".
            $next = $this->nextMeaningfulToken($tokens, $i);
            if ($next === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function nextMeaningfulToken(array $tokens, int $index): array|string|null
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
