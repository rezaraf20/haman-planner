<?php
declare(strict_types=1);

namespace App\Support;

/** Persian validation messages for the public auth forms (app locale stays "en" for the API). */
final class AuthMessages
{
    public const MESSAGES = [
        'required' => ':attribute الزامی است.',
        'string' => ':attribute نامعتبر است.',
        'email' => 'فرمت ایمیل صحیح نیست.',
        'max' => ':attribute بیش از حد طولانی است.',
        'confirmed' => 'تکرار رمز عبور با رمز عبور یکسان نیست.',
        'current_password' => 'رمز فعلی صحیح نیست.',
        'min' => ':attribute باید حداقل :min کاراکتر باشد.',
        'password.letters' => 'رمز عبور باید حداقل یک حرف داشته باشد.',
        'password.numbers' => 'رمز عبور باید حداقل یک عدد داشته باشد.',
    ];

    public const ATTRIBUTES = [
        'name' => 'نام',
        'email' => 'ایمیل',
        'password' => 'رمز عبور',
        'current_password' => 'رمز فعلی',
    ];
}
