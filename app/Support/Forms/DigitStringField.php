<?php

namespace App\Support\Forms;

use Filament\Forms\Components\TextInput;

/**
 * The input configuration for a column that holds digits but is not a number:
 * an ID number, a phone number, a session code.
 *
 * These were declared with ->numeric(), which renders <input type="number">.
 * A number has no leading zero, so "0591234567" reached the model as
 * "591234567" - stored wrong, and then refused by its own ten-digit rule the
 * next time the record was opened, on a value the user had never touched. Both
 * halves of that bug are the same mistake: treating an identifier as a
 * quantity.
 *
 * The field stays a plain text input, keeps every digit it was given, and is
 * still digits-only - enforced by the regex rule each call site already
 * carries, and by the on-screen keyboard hints set here.
 *
 * Usage:
 *     TextInput::make('child_id')
 *         ->label(__('fields.child_id'))
 *         ->required()
 *         ->rules(['regex:/^[0-9]{9}$/'])
 *         ->extraInputAttributes(DigitStringField::inputAttributes())
 */
final class DigitStringField
{
    /**
     * Attributes that ask for a numeric keypad without making the value a
     * number: inputmode picks the keypad on a phone, pattern is the HTML hint
     * for the same thing on a desktop browser.
     *
     * @return array<string, string>
     */
    public static function inputAttributes(): array
    {
        return [
            'inputmode' => 'numeric',
            'pattern' => '[0-9]*',
        ];
    }

    /**
     * Apply the whole configuration to an existing input.
     */
    public static function configure(TextInput $input): TextInput
    {
        return $input->extraInputAttributes(static::inputAttributes());
    }
}
