<?php

use App\Support\Locale\SemanticLexicon;

it('resolves a locale to its supported prefix, else English', function () {
    expect(SemanticLexicon::resolve('es-MX'))->toBe('es')
        ->and(SemanticLexicon::resolve('fr-FR'))->toBe('fr')
        ->and(SemanticLexicon::resolve('pt-BR'))->toBe('pt')
        ->and(SemanticLexicon::resolve('en'))->toBe('en')
        // Unsupported and empty both fall back to English.
        ->and(SemanticLexicon::resolve('de-DE'))->toBe('en')
        ->and(SemanticLexicon::resolve(null))->toBe('en');
});

it('renders labels per locale with {s}/{n} interpolation', function () {
    expect(SemanticLexicon::for('en')->label('new', singular: 'Client'))->toBe('New Client')
        ->and(SemanticLexicon::for('es')->label('new', singular: 'Cliente'))->toBe('Agregar Cliente')
        ->and(SemanticLexicon::for('fr')->label('new', singular: 'Client'))->toBe('Ajouter Client')
        ->and(SemanticLexicon::for('pt')->label('by_status', name: 'Pedidos'))->toBe('Pedidos por status')
        ->and(SemanticLexicon::for('fr')->label('new_order'))->toBe('Nouvelle commande');
});

it('falls back to the English template for a key the locale lacks', function () {
    // 'unknown_key' exists in no table → returns the key itself, never a fatal.
    expect(SemanticLexicon::for('fr')->label('unknown_key'))->toBe('unknown_key');
});

it('renders KPI subtitles per locale', function () {
    expect(SemanticLexicon::for('en')->kpiSubtitle('avg'))->toBe('period average')
        ->and(SemanticLexicon::for('es')->kpiSubtitle('avg'))->toBe('promedio del periodo')
        ->and(SemanticLexicon::for('pt')->kpiSubtitle('sum'))->toBe('acumulado na janela')
        ->and(SemanticLexicon::for('fr')->kpiSubtitle('count'))->toBe('total sur la fenêtre')
        ->and(SemanticLexicon::for('fr')->kpiSubtitle('unknown_agg'))->toBe('');
});

it('renders chart descriptions per locale, interpolating the field names', function () {
    expect(SemanticLexicon::for('fr')->chartDescription('hbar', 'ventes', 'région', null, null, null))
        ->toBe('Classement des région par ventes, du plus grand au plus petit.')
        ->and(SemanticLexicon::for('pt')->chartDescription('pie', 'vendas', 'categoria', null, null, null))
        ->toBe('Participação de vendas por categoria sobre o total.');
});

it('places the time bucket per locale and drops it cleanly when absent', function () {
    // English parenthesises the bucket; French folds it into the verb.
    expect(SemanticLexicon::for('en')->chartDescription('line', 'revenue', null, null, null, 'week'))
        ->toBe('Evolution of revenue (weekly) over the selected window.')
        ->and(SemanticLexicon::for('en')->chartDescription('line', 'revenue', null, null, null, null))
        ->toBe('Evolution of revenue over the selected window.')
        ->and(SemanticLexicon::for('fr')->chartDescription('line', 'ventes', null, null, null, 'month'))
        ->toBe('Évolution mensuelle de ventes sur la fenêtre sélectionnée.');
});

it('falls back to the localized record word when a chart has no measure', function () {
    expect(SemanticLexicon::for('fr')->chartDescription('area', null, null, null, null, null))
        ->toBe('Évolution de enregistrements sur la fenêtre sélectionnée.');
});

it('matches vocabulary per locale, accent- and case-insensitively', function () {
    expect(SemanticLexicon::for('fr')->matches('quantity', 'Quantité'))->toBeTrue()
        ->and(SemanticLexicon::for('fr')->matches('commerce', 'Commandes'))->toBeTrue()
        ->and(SemanticLexicon::for('fr')->matches('price', 'Prix'))->toBeTrue()
        ->and(SemanticLexicon::for('pt')->matches('commerce', 'Produtos'))->toBeTrue()
        ->and(SemanticLexicon::for('es')->matches('quantity', 'Cantidad'))->toBeTrue()
        // A budget is not a sale price, in any locale.
        ->and(SemanticLexicon::for('fr')->matches('not_price', 'Budget projet'))->toBeTrue()
        ->and(SemanticLexicon::for('fr')->matches('commerce', 'Bâtiment'))->toBeFalse();
});
