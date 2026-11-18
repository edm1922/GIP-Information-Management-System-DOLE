<x-mail::message>
# Welcome to {{ $brandName }}

Hi {{ $applicantName }},

Good news — your intern application has been **approved**. Your GIMS account has been created.

<x-mail::panel>
**Username:** {{ $username }}

**Temporary password:** {{ $temporaryPassword }}
</x-mail::panel>

Sign in here: [{{ $loginUrl }}]({{ $loginUrl }})

For your security, you will be asked to choose a new password immediately after your first sign-in. The temporary password above will stop working once you change it.

If you did not submit an application, please ignore this email or contact the PESO office.

Thanks,<br>
{{ $brandName }}
</x-mail::message>
