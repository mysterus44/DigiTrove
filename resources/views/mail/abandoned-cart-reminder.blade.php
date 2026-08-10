<x-mail::message>
# Votre panier vous attend

Vous avez laissé des articles dans votre panier. Vous pouvez reprendre là où vous
vous êtes arrêté avec le lien ci-dessous.

<x-mail::button :url="$resumeUrl">
Reprendre mon panier
</x-mail::button>

Ce lien est personnel et expire dans {{ $expiresInMinutes }} minutes.

Si vous ne souhaitez plus recevoir ce type de message, vous pouvez retirer votre
consentement depuis votre compte.

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
