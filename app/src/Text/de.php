<?php
declare(strict_types=1);

/** Zentrale deutsche Oberflaechentexte (Schluessel => Text). */
return [
    'app.name' => 'Vereinskiste',

    'nav.toggle' => 'Navigation ein- oder ausklappen',
    'nav.home' => 'Übersicht',
    'nav.accounts' => 'Konten',
    'nav.categories' => 'Kategorien',
    'nav.logout' => 'Abmelden',
    'nav.users' => 'Benutzer verwalten',

    'common.actions' => 'Aktionen',
    'common.inactive' => 'inaktiv',
    'common.rename' => 'Umbenennen',
    'common.activate' => 'Aktivieren',
    'common.deactivate' => 'Deaktivieren',

    'auth.login.title' => 'Anmelden',
    'auth.login.email' => 'E-Mail-Adresse',
    'auth.login.password' => 'Passwort',
    'auth.login.submit' => 'Anmelden',
    'auth.login.error' => 'E-Mail-Adresse oder Passwort ist falsch.',

    'home.title' => 'Übersicht',
    'home.welcome' => 'Willkommen bei der Vereinskiste.',

    'accounts.title' => 'Konten',
    'accounts.name' => 'Name',
    'accounts.type' => 'Art',
    'accounts.type.cash' => 'Bar',
    'accounts.type.bank' => 'Bank',
    'accounts.balance' => 'Saldo',
    'accounts.opening' => 'Anfangssaldo',
    'accounts.opening_balance' => 'Anfangssaldo (EUR)',
    'accounts.opening_date' => 'Eröffnungsdatum',
    'accounts.new' => 'Neues Konto',
    'accounts.create' => 'Konto anlegen',
    'accounts.empty' => 'Es sind noch keine Konten angelegt.',
    'accounts.flash.created' => 'Konto wurde angelegt.',
    'accounts.flash.renamed' => 'Konto wurde umbenannt.',
    'accounts.flash.toggled' => 'Konto wurde aktualisiert.',

    'categories.title' => 'Kategorien',
    'categories.name' => 'Name',
    'categories.new' => 'Neue Kategorie',
    'categories.create' => 'Kategorie anlegen',
    'categories.empty' => 'Es sind noch keine Kategorien angelegt.',
    'categories.flash.created' => 'Kategorie wurde angelegt.',
    'categories.flash.renamed' => 'Kategorie wurde umbenannt.',
    'categories.flash.toggled' => 'Kategorie wurde aktualisiert.',

    'validation.name.required' => 'Bitte geben Sie einen Namen an.',
    'validation.name.too_long' => 'Der Name darf höchstens 100 Zeichen lang sein.',
    'validation.category.duplicate' => 'Diese Kategorie gibt es bereits.',
    'validation.account.duplicate' => 'Dieses Konto gibt es bereits.',
    'validation.account.type' => 'Bitte wählen Sie Bar oder Bank.',
    'validation.amount.invalid' => 'Bitte geben Sie einen gültigen Betrag an.',
    'validation.date.invalid' => 'Bitte geben Sie ein gültiges Datum an.',

    'error.403.title' => 'Keine Berechtigung',
    'error.403.text' => 'Für diese Seite fehlt Ihnen die Berechtigung.',
    'error.404.title' => 'Nicht gefunden',
    'error.404.text' => 'Der gewünschte Eintrag wurde nicht gefunden.',
    'error.csrf.title' => 'Sicherheitsprüfung fehlgeschlagen',
    'error.csrf.text' => 'Die Anfrage konnte nicht bestätigt werden. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
];
