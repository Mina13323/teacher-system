import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/toast';

/**
 * WhatsApp student contact — a deep-link workflow only.
 *
 * Nothing here sends a message. It opens https://wa.me on the staff member's
 * own device with the text pre-filled; the staff member reviews it and presses
 * Send themselves. There is no WhatsApp API, no provider, no automation.
 *
 * The destination is always the STUDENT's number. The sender is whoever's
 * WhatsApp account is signed in on this device.
 */
export const WHATSAPP_MESSAGE_TYPES = Object.freeze({
    GENERAL: 'general',
    CREDENTIALS: 'credentials',
    RENEWAL: 'renewal',
});

/**
 * Controlled template map. `buildMessage` only accepts a key from here, so an
 * arbitrary caller-supplied message_type can never select a sensitive template.
 */
const TEMPLATE_KEYS = Object.freeze({
    [WHATSAPP_MESSAGE_TYPES.GENERAL]: 'whatsapp.templateGeneral',
    [WHATSAPP_MESSAGE_TYPES.CREDENTIALS]: 'whatsapp.templateCredentials',
    [WHATSAPP_MESSAGE_TYPES.RENEWAL]: 'whatsapp.templateRenewal',
});

export function useWhatsApp() {
    const { t } = useI18n();
    const toast = useToast();

    /**
     * Render a template through i18n with named interpolation.
     *
     * Messages are never concatenated from translated fragments, and no message
     * text is hardcoded in a component.
     *
     * @returns {string|null} null for an unknown type or a missing destination
     */
    function buildMessage(type, context = {}) {
        const key = TEMPLATE_KEYS[type];

        return key ? t(key, context) : null;
    }

    /**
     * Build the wa.me deep link.
     *
     * `whatsappPhone` must already be normalized E.164 digits (supplied by the
     * API as `whatsapp_phone`). Normalization is deliberately NOT repeated here,
     * so the client can never disagree with the stored number.
     *
     * The message is encoded with encodeURIComponent, which handles Arabic,
     * line breaks and punctuation correctly — no manual replacement.
     */
    function buildUrl(whatsappPhone, message) {
        if (!whatsappPhone || !message) return null;

        return `https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`;
    }

    /**
     * Open WhatsApp.
     *
     * MUST be called synchronously from the user gesture. Anything awaited
     * before this call makes the browser treat the window as a popup and block
     * it, so callers resolve the message first and only then invoke this.
     */
    function openWhatsApp(whatsappPhone, message) {
        const url = buildUrl(whatsappPhone, message);

        if (!url) {
            toast.error(t('whatsapp.invalidPhone'));

            return false;
        }

        window.open(url, '_blank', 'noopener,noreferrer');
        toast.info(t('whatsapp.opened'));

        return true;
    }

    /**
     * Copy the message so staff can paste it if WhatsApp does not open.
     * Nothing is logged — the payload may contain a temporary password.
     */
    async function copyMessage(message) {
        if (!message) return false;

        try {
            await navigator.clipboard.writeText(message);
            toast.success(t('whatsapp.copied'));

            return true;
        } catch {
            toast.error(t('whatsapp.copyFailed'));

            return false;
        }
    }

    return { buildMessage, buildUrl, openWhatsApp, copyMessage };
}
