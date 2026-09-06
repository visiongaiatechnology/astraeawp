<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Forms;

/**
 * Frontend Form Markup and Shortcode Renderer.
 *
 * Emits accessible form controls, honeypot traps, and CSRF nonces
 * wrapped in Astraea Glassmorphism design tokens.
 *
 * @package Astraea\Forms
 */
final class FormRenderer {

    public static function init(): void {
        add_shortcode('astraea_form', [self::class, 'renderShortcode']);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function renderShortcode(array $atts): string {
        $id = sanitize_key((string)($atts['id'] ?? 'default'));
        $form = self::getForm($id);
        if ($form === null) {
            return '<!-- Astraea Form: Form not found -->';
        }

        return self::renderForm($form);
    }

    public static function renderForm(FormDefinition $form): string {
        $nonceAction = 'astraea_form_submit_' . $form->id;
        $nonce = wp_create_nonce($nonceAction);
        $actionUrl = admin_url('admin-post.php');

        ob_start();
        ?>
        <form class="astraea-form astraea-glass-surface-l2" method="post" action="<?php echo esc_url($actionUrl); ?>" enctype="multipart/form-data" style="padding: 24px; border-radius: 12px; max-width: 600px; margin: 20px 0; background: rgba(15, 23, 42, 0.75); border: 1px solid rgba(255, 255, 255, 0.08); color: #f8fafc;">
            <input type="hidden" name="action" value="astraea_form_submit">
            <input type="hidden" name="astraea_form_id" value="<?php echo esc_attr($form->id); ?>">
            <input type="hidden" name="_astraea_form_nonce" value="<?php echo esc_attr($nonce); ?>">

            <!-- Honeypot Bot Trap (Invisible to humans, irresistible to scrapers) -->
            <div style="display: none !important; opacity: 0; position: absolute; left: -9999px;">
                <label for="_astraea_hp_<?php echo esc_attr($form->id); ?>">Leave this field blank</label>
                <input type="text" id="_astraea_hp_<?php echo esc_attr($form->id); ?>" name="_astraea_hp_check" value="" tabindex="-1" autocomplete="off">
            </div>

            <h3 style="margin-top: 0; margin-bottom: 16px; color: #f8fafc; font-size: 18px;"><?php echo esc_html($form->title); ?></h3>

            <?php foreach ($form->fields as $field): ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin-bottom: 6px;">
                        <?php echo esc_html($field->label); ?>
                        <?php if ($field->required): ?><span style="color: #f87171;">*</span><?php endif; ?>
                    </label>

                    <?php if ($field->type === 'textarea'): ?>
                        <textarea name="<?php echo esc_attr($field->name); ?>" rows="4" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;" placeholder="<?php echo esc_attr($field->placeholder); ?>" <?php if ($field->required) echo 'required'; ?>></textarea>

                    <?php elseif ($field->type === 'select'): ?>
                        <select name="<?php echo esc_attr($field->name); ?>" style="width: 100%; padding: 10px; background: rgba(30,41,59,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;" <?php if ($field->required) echo 'required'; ?>>
                            <?php foreach ($field->options as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($field->type === 'consent' || $field->type === 'checkbox'): ?>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #cbd5e1; cursor: pointer;">
                            <input type="checkbox" name="<?php echo esc_attr($field->name); ?>" value="1" <?php if ($field->required) echo 'required'; ?>>
                            <span><?php echo esc_html($field->placeholder ?: $field->label); ?></span>
                        </label>

                    <?php elseif ($field->type === 'file'): ?>
                        <input type="file" name="<?php echo esc_attr($field->name); ?>" style="color: #cbd5e1; font-size: 13px;" <?php if ($field->required) echo 'required'; ?>>

                    <?php else: ?>
                        <input type="<?php echo esc_attr($field->type); ?>" name="<?php echo esc_attr($field->name); ?>" placeholder="<?php echo esc_attr($field->placeholder); ?>" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; font-size: 13px;" <?php if ($field->required) echo 'required'; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="astraea-btn astraea-btn-primary" style="background: #0284c7; border: 1px solid #38bdf8; color: #fff; font-size: 14px; font-weight: 600; padding: 10px 24px; border-radius: 6px; cursor: pointer;">
                Submit Form
            </button>
        </form>
        <?php
        return (string)ob_get_clean();
    }

    public static function getForm(string $id): ?FormDefinition {
        $forms = get_option('astraea_forms_definitions', []);
        if (is_array($forms) && isset($forms[$id]) && is_array($forms[$id])) {
            try {
                return FormDefinition::fromArray($forms[$id]);
            } catch (\Throwable) {
                return null;
            }
        }

        // Return default contact form if requested
        if ($id === 'default' || $id === 'contact') {
            return new FormDefinition(
                id: 'contact',
                title: 'Contact Us',
                fields: [
                    new FormField('name', 'Your Name', 'text', true, [], 'Enter your name'),
                    new FormField('email', 'Your Email', 'email', true, [], 'name@example.com'),
                    new FormField('message', 'Your Message', 'textarea', true, [], 'How can we help?'),
                    new FormField('consent', 'Privacy Consent', 'consent', true, [], 'I consent to processing my submitted data for inquiry handling.'),
                ],
                notifyEmail: (string)get_option('admin_email'),
                storeEncrypted: true
            );
        }

        return null;
    }
}
