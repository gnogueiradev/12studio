/** Tipos das conversas da montra (App\Store\ConversationPresenter). */

export type ConversationType = 'contact' | 'interest' | 'quote';
export type ConversationStatus = 'open' | 'answered' | 'closed';

export type ConversationAttachmentData = {
    id: number;
    name: string;
    size: number;
    url: string;
};

export type ConversationProduct = {
    id: number;
    name: string;
    href: string;
    image: string | null;
    variant: string | null;
};

/** A conversa como o cliente a vê (sem notas internas). */
export type CustomerConversation = {
    reference: string;
    type: ConversationType;
    status: ConversationStatus;
    createdAt: string | null;
    product: ConversationProduct | null;
    quantity: number | null;
    personalization: Record<string, string> | null;
    details: Record<string, string | number> | null;
    replyUrl: string;
    messages: {
        id: number;
        mine: boolean;
        author: string;
        body: string;
        createdAt: string | null;
        attachments: ConversationAttachmentData[];
    }[];
};

/** Uma linha da caixa de entrada. */
export type ConversationRow = {
    id: number;
    reference: string;
    type: ConversationType;
    status: ConversationStatus;
    subject: string;
    name: string;
    email: string;
    unread: boolean;
    lastMessageAt: string | null;
    createdAt: string | null;
};

/** A conversa no backoffice. */
export type AdminConversation = ConversationRow & {
    phone: string | null;
    locale: string;
    product: ConversationProduct | null;
    quantity: number | null;
    personalization: Record<string, string> | null;
    details: Record<string, string | number> | null;
    orderDraftId: number | null;
    consentAt: string | null;
    messages: {
        id: number;
        author: 'customer' | 'staff';
        authorName: string;
        body: string;
        internal: boolean;
        createdAt: string | null;
        attachments: ConversationAttachmentData[];
    }[];
};
