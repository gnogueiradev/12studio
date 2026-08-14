import { TabsTrigger } from '@/components/ui/tabs';

type Props = {
    value: string;
    children: React.ReactNode;
    /** Há erros de validação em campos que vivem neste separador. */
    invalid?: boolean;
};

/**
 * Separador de um formulário, com aviso de erro.
 *
 * Um separador fechado esconde os campos que estão lá dentro, e com eles as
 * mensagens de validação — submeter e ver o formulário recusar sem dizer onde é
 * o pior que um formulário por separadores pode fazer. O ponto resolve isso:
 * diz em que separador está o problema sem obrigar a abri-los todos.
 *
 * O ponto é decorativo (`aria-hidden`); quem usa leitor de ecrã recebe a mesma
 * informação pelo texto do `sr-only`, porque uma cor sozinha nunca é um aviso.
 */
export function FormTab({ value, children, invalid = false }: Props) {
    return (
        <TabsTrigger value={value}>
            {children}
            {invalid && (
                <>
                    <span
                        aria-hidden
                        className="size-1.5 rounded-full bg-destructive"
                    />
                    <span className="sr-only">(tem erros por corrigir)</span>
                </>
            )}
        </TabsTrigger>
    );
}
