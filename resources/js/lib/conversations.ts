import type {
    ConversationStatus,
    ConversationType,
} from '@/types/conversation';

/** Rótulos do backoffice (em português) para as conversas da montra. */
export const CONVERSATION_STATUS_LABELS: Record<ConversationStatus, string> = {
    open: 'Por responder',
    answered: 'Respondida',
    closed: 'Fechada',
};

export const CONVERSATION_TYPE_LABELS: Record<ConversationType, string> = {
    contact: 'Contacto',
    interest: 'Tenho interesse',
    quote: 'Peça personalizada',
};

/** O tom da pastilha de estado. */
export const CONVERSATION_STATUS_TONES: Record<ConversationStatus, string> = {
    open: 'bg-warning-soft text-warning-soft-foreground',
    answered: 'bg-success-soft text-success-soft-foreground',
    closed: 'bg-muted text-muted-foreground',
};
