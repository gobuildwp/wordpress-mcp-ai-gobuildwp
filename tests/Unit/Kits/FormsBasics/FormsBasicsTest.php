<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\FormsBasics;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\FormsBasics as F;
use WPPilot\Kits\Runtime;

/**
 * The forms-basics kit: the redaction rule (Pro's sensitive fields plus contact details), each
 * vendor's form list and redacted entries, bootstrap listing only active vendors, and standing
 * aside when GoBuildWP Pro already registered a name.
 */
final class FormsBasicsTest extends TestCase
{
    private const NAMES = [
        'wppilot/wpforms-list-forms', 'wppilot/wpforms-list-entries',
        'wppilot/cf7-list-forms', 'wppilot/cf7-list-entries',
        'wppilot/gravityforms-list-forms', 'wppilot/gravityforms-list-entries',
        'wppilot/forminator-list-forms', 'wppilot/forminator-list-entries',
    ];

    private mixed $savedWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        Runtime\host(new ReadHost());
    }

    protected function setUp(): void
    {
        Caps::$granted = ['manage_forminator', 'flamingo_edit_inbound_messages'];
        Registrations::$args = [];
        Store::reset();
        $this->setWpformsPro(true);
        \GFForms::$version = '2.9.1';
        \GFAPI::$forms = [];
        \GFAPI::$entries = [];
        \GFAPI::$calls = [];
        \GFAPI::$canView = true;
        \WPCF7_ContactForm::$forms = [];
        \Flamingo_Inbound_Message::$messages = [];
        \Forminator_Base_Form_Model::$forms = [];
        \Forminator_Form_Entry_Model::$entries = [];
        \wpforms()->entry->rows = [];
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        $this->setWpformsPro(false);
        @rmdir(WPFORMS_PLUGIN_DIR . 'pro');
        @rmdir(WPFORMS_PLUGIN_DIR);
    }

    // Registration.

    public function testEveryActiveVendorsAbilitiesRegisterAsReadsInTheFormsCategory(): void
    {
        $kit = $this->bootstrap();
        $this->registerAbilities($kit);

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/forms-basics/kit.json'), true);
        self::assertSame(self::NAMES, array_column($manifest['abilities'], 'name'));
        self::assertSame('Forms', $manifest['categories']['forms']['label']);
        foreach (self::NAMES as $name) {
            $args = Registrations::$args[$name] ?? null;
            self::assertIsArray($args, $name);
            self::assertSame('forms', $args['category'], $name);
            self::assertTrue($args['meta']['annotations']['readonly'], $name);
            self::assertFalse($args['meta']['annotations']['destructive'], $name);
        }
        foreach (['wppilot/wpforms-list-entries', 'wppilot/cf7-list-entries', 'wppilot/gravityforms-list-entries', 'wppilot/forminator-list-entries'] as $name) {
            $args = Registrations::$args[$name];
            self::assertArrayNotHasKey('include_sensitive', $args['input_schema']['properties'], "{$name} has no way to ask for the withheld values");
            self::assertArrayNotHasKey('search', $args['input_schema']['properties'], "{$name} cannot confirm a guessed value by search");
            self::assertStringContainsString('[REDACTED]', $args['description'], $name);
            // Pro has no reveal for Forminator, so its description sends a person to Forminator's own screen.
            self::assertStringContainsString(
                $name === 'wppilot/forminator-list-entries' ? 'Forminator > Submissions' : 'Pro edition',
                $args['description'],
                $name,
            );
        }
    }

    public function testBootstrapListsOnlyTheActiveVendorsFiles(): void
    {
        \GFForms::$version = '2.6.9'; // below the floor

        $files = array_map('basename', $this->bootstrap()['ability_files']);

        self::assertSame(['wpforms.php', 'cf7.php', 'forminator.php'], $files);
    }

    public function testForminatorNeedsItsOwnAdminCapability(): void
    {
        $this->registerAbilities($this->bootstrap());
        $permission = Registrations::$args['wppilot/forminator-list-entries']['permission_callback'];

        self::assertTrue($permission());
        Caps::$granted = [];
        self::assertFalse($permission());
    }

    // The redaction rule.

    public function testProsSensitiveFieldsAreWithheldByTypeAndByLabel(): void
    {
        self::assertSame(['value' => '[REDACTED]', 'redacted' => 'password'], F\redact('password', 'Password', 'hunter2'));
        self::assertSame('credit_card', F\redact('stripe-credit-card', 'Card', '4242')['redacted']);
        self::assertSame('credit_card', F\redact('creditcard', '', ['4242', '12/30'])['redacted']);
        self::assertSame('file_upload', F\redact('file-upload', 'CV', 'https://x.test/cv.pdf')['redacted']);
        self::assertSame('file_upload', F\redact('post_image', 'Photo', 'a.jpg')['redacted']);
        self::assertSame('signature', F\redact('signature', 'Sign', 'data:image/png')['redacted']);
        self::assertSame('credit_card', F\redact('text', 'Card number', '4242 4242 4242 4242')['redacted'], 'a text field labelled as a card');
        self::assertSame('password', F\redact('text', 'Your password', 'x')['redacted']);
    }

    public function testEmailAndPhoneAreWithheldByTypeAndByLabel(): void
    {
        self::assertSame(['value' => '[REDACTED]', 'redacted' => 'email'], F\redact('email', 'Email', 'ada@example.com'));
        self::assertSame('phone', F\redact('phone', 'Phone', '07700 900123')['redacted']);
        self::assertSame('phone', F\redact('tel', 'your-phone', '123')['redacted']);
        self::assertSame('email', F\redact('text', 'Your e-mail', 'not an address')['redacted'], 'a text field that asks for an email');
        self::assertSame('phone', F\redact('text', 'Mobile number', 'call me')['redacted']);
        self::assertSame('ip', F\redact('ip', '', '172.21.0.1')['redacted']);
        self::assertSame(['value' => '', 'redacted' => null], F\redact('email', 'Email', ''), 'blank stays blank, so "left empty" is told apart from "withheld"');
    }

    public function testContactDetailsAreCutOutOfFreeText(): void
    {
        $masked = F\redact('textarea', 'Message', 'Call +1 (555) 010-2030 or mail ada.alt@example.org about order 4521 from 2024');

        self::assertSame('Call [REDACTED] or mail [REDACTED] about order 4521 from 2024', $masked['value']);
        self::assertSame('contact_in_text', $masked['redacted']);
        self::assertSame(['value' => 'Ada Lovelace', 'redacted' => null], F\redact('name', 'Name', 'Ada Lovelace'));
        self::assertSame('2026-09-28 12:30:00', F\redact('date', 'When', '2026-09-28 12:30:00')['value'], 'a date is not a phone number');
        self::assertSame(['[REDACTED]', 'plain'], F\redact('checkbox', 'Pick', ['a@b.co', 'plain'])['value']);
        $long = F\redact('textarea', 'Essay', str_repeat('é', 3000))['value'];
        self::assertLessThanOrEqual(F\MAX_VALUE_BYTES + 3, strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    // WPForms.

    public function testWpformsFormsWithEntryCountsOnProAndNullOnLite(): void
    {
        Store::post(5, 'wpforms', 'Contact');
        Store::post(6, 'wpforms', 'Quote', 'draft');
        Store::post(7, 'wpforms', 'Old', 'trash');
        Store::$sqlRows = [(object) ['form_id' => '5', 'c' => '3']];

        $list = F\wpforms_list_forms([]);

        self::assertSame(2, $list['total'], 'trash is left out of "any"');
        self::assertSame([5, 6], array_column($list['forms'], 'id'));
        self::assertSame([3, 0], array_column($list['forms'], 'entry_count'));
        self::assertSame(['id', 'title', 'slug', 'status', 'date_created', 'date_modified', 'entry_count'], array_keys($list['forms'][0]));
        self::assertSame([50, 0], [$list['limit'], $list['offset']]);

        $this->setWpformsPro(false);
        self::assertSame([null, null], array_column(F\wpforms_list_forms([])['forms'], 'entry_count'));
    }

    public function testWpformsEntriesAreRedactedAndNeedWpformsPro(): void
    {
        Store::post(5, 'wpforms', 'Contact');
        \wpforms()->entry->rows = [
            (object) [
                'entry_id' => 41, 'form_id' => 5, 'status' => '', 'type' => '', 'viewed' => '1', 'starred' => '0',
                'date' => '2026-09-28 10:00:00', 'date_modified' => '2026-09-28 10:00:00', 'user_id' => 0, 'ip_address' => '203.0.113.9',
                'fields' => (string) json_encode([
                    '1' => ['id' => 1, 'type' => 'name', 'name' => 'Name', 'value' => 'Ada Lovelace'],
                    '2' => ['id' => 2, 'type' => 'email', 'name' => 'Email', 'value' => 'ada@example.com'],
                    '3' => ['id' => 3, 'type' => 'textarea', 'name' => 'Message', 'value' => 'ring 07700 900123 please'],
                    '4' => ['id' => 4, 'type' => 'file-upload', 'name' => 'CV', 'value' => 'https://x.test/wp-content/uploads/cv.pdf'],
                ]),
            ],
        ];

        $result = F\wpforms_list_entries(['form_id' => 5, 'date_after' => '2026-09-01', 'starred' => false]);

        self::assertIsArray($result);
        self::assertSame([1, F\REDACTION_MODE], [$result['total'], $result['redaction']]);
        $entry = $result['entries'][0];
        self::assertSame([41, 5, true, false], [$entry['entry_id'], $entry['form_id'], $entry['viewed'], $entry['starred']]);
        self::assertArrayNotHasKey('ip_address', $entry);
        $answers = array_column($entry['answers'], null, 'field_id');
        self::assertSame('Ada Lovelace', $answers['1']['value']);
        self::assertSame(['[REDACTED]', 'email'], [$answers['2']['value'], $answers['2']['redacted']]);
        self::assertSame(['ring [REDACTED] please', 'contact_in_text'], [$answers['3']['value'], $answers['3']['redacted']]);
        self::assertSame('file_upload', $answers['4']['redacted']);
        $json = (string) json_encode($result);
        foreach (['ada@example.com', '900123', 'cv.pdf', '203.0.113'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $call = \wpforms()->entry->calls[0];
        self::assertSame(['2026-09-01 00:00:00', '2999-12-31 23:59:59'], $call['date']);
        self::assertSame('0', $call['starred']);

        $this->setWpformsPro(false);
        $lite = F\wpforms_list_entries(['form_id' => 5]);
        self::assertInstanceOf(WP_Error::class, $lite);
        self::assertSame('wpforms_pro_required', $lite->get_error_code());
    }

    public function testWpformsEntriesNeedAConcreteFormAndItsCapability(): void
    {
        Store::post(5, 'wpforms', 'Contact');

        self::assertSame('wpforms_invalid_input', $this->errorCode(F\wpforms_list_entries([])));
        self::assertSame('wpforms_form_not_found', $this->errorCode(F\wpforms_list_entries(['form_id' => 99])));
        self::assertSame('forms_bad_date', $this->errorCode(F\wpforms_list_entries(['form_id' => 5, 'date_before' => '28/09/2026'])));
        Store::$wpformsCaps = [];
        self::assertSame('wpforms_capability_missing', $this->errorCode(F\wpforms_list_entries(['form_id' => 5])));
    }

    // Contact Form 7 and Flamingo.

    public function testCf7FormsAreCompactAndSearchable(): void
    {
        Store::post(20, 'wpcf7_contact_form', 'Contact form 1');
        Store::post(21, 'wpcf7_contact_form', 'Newsletter', 'draft');
        Store::post(22, 'wpcf7_contact_form', 'Binned', 'trash');
        \WPCF7_ContactForm::$forms = [
            20 => ['template' => '[text* your-name] [email* your-email] [submit "Send"]', 'locale' => 'en_US', 'tags' => []],
            21 => ['template' => '[email your-email]', 'locale' => 'it', 'tags' => []],
            22 => ['template' => '', 'locale' => 'en_US', 'tags' => []],
        ];

        $list = F\cf7_list_forms([]);

        self::assertSame(2, $list['total']);
        self::assertSame(['id', 'title', 'slug', 'status', 'locale', 'hash', 'tag_count', 'modified'], array_keys($list['forms'][0]));
        self::assertSame(3, $list['forms'][0]['tag_count']);
        self::assertSame([21], array_column(F\cf7_list_forms(['search' => 'NEWS'])['forms'], 'id'));
        self::assertSame(['forms' => [], 'total' => 0], F\cf7_list_forms(['status' => 'bogus']));
    }

    public function testCf7EntriesComeFromFlamingoRedacted(): void
    {
        Store::post(20, 'wpcf7_contact_form', 'Contact form 1');
        \WPCF7_ContactForm::$forms[20] = ['template' => '', 'locale' => 'en_US', 'tags' => [['your-name', 'text'], ['your-email', 'email'], ['your-tel', 'tel'], ['your-message', 'textarea'], ['cv', 'file']]];
        Store::$meta[20]['_flamingo'] = ['channel' => 9];
        $message = new \Flamingo_Inbound_Message(300);
        $message->channel = 9;
        $message->subject = 'Hello from ada@example.com';
        $message->fields = ['your-name' => 'Ada', 'your-email' => 'ada@example.com', 'your-tel' => '+44 7700 900123', 'your-message' => 'Mail me at ada.alt@example.org', 'cv' => '/uploads/cv.pdf'];
        $message->meta = ['remote_ip' => '203.0.113.9'];
        \Flamingo_Inbound_Message::$messages = [$message];
        Store::post(300, 'flamingo_inbound', 'Hello', 'publish');

        $result = F\cf7_list_entries(['form_id' => 20, 'date_to' => '2026-09-30']);

        self::assertIsArray($result);
        self::assertSame([20, 1], [$result['form_id'], $result['total']]);
        $entry = $result['entries'][0];
        self::assertSame(['inbox', 'Hello from [REDACTED]'], [$entry['status'], $entry['subject']]);
        $answers = array_column($entry['answers'], null, 'field_id');
        self::assertSame('Ada', $answers['your-name']['value']);
        self::assertSame(['email', 'phone', 'contact_in_text', 'file_upload'], [$answers['your-email']['redacted'], $answers['your-tel']['redacted'], $answers['your-message']['redacted'], $answers['cv']['redacted']]);
        $json = (string) json_encode($result);
        foreach (['ada@example.com', '900123', 'ada.alt', 'cv.pdf', '203.0.113'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $find = \Flamingo_Inbound_Message::$finds[0];
        self::assertSame([9, 'publish'], [$find['channel_id'], $find['post_status']]);
        self::assertSame([['before' => '2026-09-30 23:59:59', 'inclusive' => true]], $find['date_query']);
    }

    public function testCf7EntriesNeedFlamingosCapabilityAndAStoredChannel(): void
    {
        Store::post(20, 'wpcf7_contact_form', 'Contact form 1');
        \WPCF7_ContactForm::$forms[20] = ['template' => '', 'locale' => 'en_US', 'tags' => []];

        $none = F\cf7_list_entries(['form_id' => 20]);
        self::assertSame([0, []], [$none['total'], $none['entries']], 'a form that never stored a submission has no channel');
        self::assertSame('cf7_form_not_found', $this->errorCode(F\cf7_list_entries(['form_id' => 99])));
        Caps::$granted = [];
        self::assertSame('cf7_capability_missing', $this->errorCode(F\cf7_list_entries(['form_id' => 20])));
    }

    // Gravity Forms.

    public function testGravityFormsListByStatusWithCounts(): void
    {
        \GFAPI::$forms = [
            1 => ['id' => 1, 'title' => 'Contact', 'is_active' => 1, 'is_trash' => 0, 'date_created' => '2026-09-01 10:00:00', 'date_updated' => '2026-09-20 10:00:00'],
            2 => ['id' => 2, 'title' => 'Survey', 'is_active' => 0, 'is_trash' => 0, 'date_created' => '2026-09-02 10:00:00', 'date_updated' => '2026-09-10 10:00:00'],
            3 => ['id' => 3, 'title' => 'Gone', 'is_active' => 1, 'is_trash' => 1, 'date_created' => '2026-09-03 10:00:00'],
        ];
        Store::$sqlRows = [(object) ['form_id' => '1', 'total' => '4']];

        $list = F\gf_list_forms([]);

        self::assertSame(2, $list['total']);
        self::assertSame([[1, 'active', 4], [2, 'inactive', 0]], array_map(static fn(array $f): array => [$f['id'], $f['status'], $f['entry_count']], $list['forms']));
        self::assertSame([3], array_column(F\gf_list_forms(['status' => 'trash'])['forms'], 'id'));
        self::assertSame([2], array_column(F\gf_list_forms(['search' => 'surv'])['forms'], 'id'));
        self::assertSame([1, 2], array_column(F\gf_list_forms(['orderby' => 'id', 'order' => 'asc'])['forms'], 'id'));
    }

    public function testGravityFormsEntriesAreRedacted(): void
    {
        \GFAPI::$forms[1] = [
            'id' => 1, 'title' => 'Contact', 'is_active' => 1, 'is_trash' => 0,
            'fields' => [
                ['id' => 1, 'type' => 'name', 'label' => 'Name', 'inputs' => [['id' => '1.3', 'label' => 'First'], ['id' => '1.6', 'label' => 'Last']]],
                ['id' => 2, 'type' => 'email', 'label' => 'Email'],
                ['id' => 3, 'type' => 'textarea', 'label' => 'Comments'],
                (object) ['id' => 4, 'type' => 'creditcard', 'label' => 'Card', 'inputs' => [['id' => '4.1', 'label' => 'Number']]],
                ['id' => 5, 'type' => 'section', 'label' => 'Break'],
            ],
        ];
        \GFAPI::$entries = [[
            'id' => 70, 'form_id' => 1, 'status' => 'active', 'date_created' => '2026-09-28 09:00:00', 'is_read' => '1', 'is_starred' => '0', 'ip' => '198.51.100.4',
            '1.3' => 'Ada', '1.6' => 'Lovelace', '2' => 'ada@example.com', '3' => 'Text me: 0161 496 0000', '4.1' => 'XXXXXXXXXXXX4242',
        ]];

        $result = F\gf_list_entries(['form_id' => 1, 'read' => true, 'date_from' => '2026-09-01']);

        self::assertIsArray($result);
        $entry = $result['entries'][0];
        self::assertSame([70, true, false, '[REDACTED]'], [$entry['id'], $entry['is_read'], $entry['is_starred'], $entry['ip']]);
        $answers = array_column($entry['answers'], null, 'field_id');
        self::assertSame(['First' => 'Ada', 'Last' => 'Lovelace'], $answers['1']['value'], 'a multi-input field by input label');
        self::assertSame(['email', 'contact_in_text', 'credit_card'], [$answers['2']['redacted'], $answers['3']['redacted'], $answers['4']['redacted']]);
        self::assertArrayNotHasKey('5', $answers, 'layout fields hold no answer');
        $call = \GFAPI::$calls[0];
        self::assertSame('active', $call['criteria']['status']);
        self::assertSame('2026-09-01 00:00:00', $call['criteria']['start_date']);
        self::assertSame([['key' => 'is_read', 'value' => 1], 'mode' => 'all'], $call['criteria']['field_filters']);
        self::assertSame(['offset' => 0, 'page_size' => 20], $call['paging']);

        \GFAPI::$canView = false;
        self::assertSame('gravityforms_capability_missing', $this->errorCode(F\gf_list_entries(['form_id' => 1])));
        self::assertSame('gravityforms_form_not_found', $this->errorCode(F\gf_list_entries(['form_id' => 9])));
    }

    // Forminator.

    public function testForminatorFormsAndRedactedEntries(): void
    {
        \Forminator_Base_Form_Model::$forms[7] = [
            'name' => 'contact-us',
            'status' => 'publish',
            'settings' => ['formName' => 'Contact Us'],
            'fields' => [
                'name-1' => ['type' => 'name', 'field_label' => 'Name'],
                'email-1' => ['type' => 'email', 'field_label' => 'Email'],
                'html-1' => ['type' => 'html', 'field_label' => ''],
                'textarea-1' => ['type' => 'textarea', 'field_label' => 'Message'],
                'upload-1' => ['type' => 'upload', 'field_label' => 'Attachment'],
            ],
        ];
        $list = F\forminator_list_forms([]);
        self::assertSame(['Contact Us', 4, '[forminator_form id="7"]'], [$list['forms'][0]['title'], $list['forms'][0]['field_count'], $list['forms'][0]['shortcode']]);

        $entry = new \Forminator_Form_Entry_Model();
        $entry->entry_id = 11;
        $entry->date_created_sql = '2026-09-28 20:58:04';
        $entry->meta_data = [
            'name-1' => ['value' => 'Ada Lovelace'],
            'email-1' => ['value' => 'ada@example.com'],
            'textarea-1' => ['value' => 'ring 07700 900123'],
            'upload-1' => ['value' => ['file' => ['file_url' => 'https://x.test/cv.pdf']]],
            '_forminator_user_ip' => ['value' => '172.21.0.1'],
        ];
        \Forminator_Form_Entry_Model::$entries = [$entry];

        $entries = F\forminator_list_entries(['form_id' => 7, 'date_from' => '2026-09-01', 'limit' => 5]);

        $args = \Forminator_Form_Entry_Model::$last_args;
        self::assertSame(['2026-09-01', '9999-12-31'], $args['date_created']);
        self::assertSame([0, 'active'], [$args['is_spam'], $args['status']]);
        $row = $entries['entries'][0];
        self::assertSame(['Ada Lovelace', '[REDACTED]', '2026-09-28 20:58:04'], [$row['answers'][0]['value'], $row['ip'], $row['date_created']]);
        self::assertSame('file_upload', $row['answers'][3]['redacted']);
        $json = (string) json_encode($entries);
        foreach (['ada@example.com', '900123', '172.21', 'cv.pdf'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame('forminator_bad_date', $this->errorCode(F\forminator_list_entries(['form_id' => 7, 'date_to' => '28/09/2026'])));
        self::assertSame('forminator_form_not_found', $this->errorCode(F\forminator_list_entries(['form_id' => 99])));
    }

    /** @return array<string, mixed> */
    private function bootstrap(): array
    {
        return require dirname(__DIR__, 4) . '/includes/kits/forms-basics/bootstrap.php';
    }

    /** @param array<string, mixed> $kit */
    private function registerAbilities(array $kit): void
    {
        foreach ($kit['ability_files'] as $file) {
            require $file;
        }
    }

    private function setWpformsPro(bool $pro): void
    {
        $marker = WPFORMS_PLUGIN_DIR . 'pro/wpforms-pro.php';
        if ($pro) {
            @mkdir(dirname($marker), 0777, true);
            file_put_contents($marker, '<?php');
        } elseif (is_file($marker)) {
            unlink($marker);
        }
    }

    // Last: it leaves the names claimed in the shared registry, which the tests above need free.
    public function testTheKitStandsAsideWhenAnotherPluginRegisteredTheNamesFirst(): void
    {
        foreach (self::NAMES as $name) {
            if (!\wp_has_ability($name)) {
                \wp_register_ability($name, ['label' => 'Registered first elsewhere']);
            }
        }

        $this->registerAbilities($this->bootstrap());

        self::assertSame([], Registrations::$args);
    }

    private function errorCode(mixed $result): string
    {
        return $result instanceof WP_Error ? (string) $result->get_error_code() : 'not an error';
    }
}
