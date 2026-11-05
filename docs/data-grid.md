# Gemeinsame Tabellen

`components/data_grid.twig` rendert Tabellen mit Suche, Sortierung, Seitengroesse,
Seitennavigation, Leerzustaenden und einer fixierten Aktionsspalte. Ohne JavaScript
bleiben alle gelieferten Zeilen und Aktionen sichtbar. Mobil nutzt die Komponente
die vorhandene Kartenansicht. Es werden keine Daten im Browser gespeichert.

```twig
{% embed 'components/data_grid.twig' with {
    label: 'Beispiele',
    empty_text: 'Keine Eintraege vorhanden.',
    rows: entries,
    columns: [
        {key: 'name', label: 'Name'},
        {key: 'created_at', label: 'Erstellt', type: 'date'},
        {key: 'amount', label: 'Betrag', type: 'money'},
        {key: 'actions', label: 'Aktion', actions: true}
    ]
} %}
    {% block cell %}
        {% if column.key == 'actions' %}
            <a href="/examples/{{ row.id }}">Oeffnen</a>
        {% else %}{{ parent() }}{% endif %}
    {% endblock %}
{% endembed %}
```

Spalten unterstuetzen `sort_key` fuer abweichende Sortierwerte, `sort_values`
fuer die Zuordnung von Rohwerten zu uebersetzten Beschriftungen und
`sortable: false`. `type: 'badge'` erwartet `values`, eine Zuordnung von
Rohwerten zu `{label, tone}`. Badge-Spalten sortieren nach ihrer Beschriftung,
Datumsspalten nach dem Zeitstempel. Standardspalten geben Text escaped aus.
Eigene Zellen erhalten `row` und `column`; `parent()` verwendet den Standard.
Der Block `filters` nimmt zusaetzliche serverseitige Filterlinks auf.

Suche und Seitennavigation beziehen sich ausschliesslich auf gelieferte Zeilen,
nicht auf die gesamte Datenbank. Bestehende Berechtigungen, Datenlimits und
serverseitige Filter gelten weiterhin. Bei grossen Datenmengen braucht die
betreffende Ansicht eine serverseitige Suche und Pagination; keine vollstaendige
Datenbank laden, um diese Browser-Komponente zu verwenden.

Beispiele: `members/_applications_table.twig`, `members/_application_messages.twig`.
