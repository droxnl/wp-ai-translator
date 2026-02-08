/* global wpNavMenu */
jQuery( function( $ ) {
    var $metaBox = $( '#wpait-language-menu' );

    if ( ! $metaBox.length || 'undefined' === typeof wpNavMenu ) {
        return;
    }

    $( '#submit-wpait-language-menu' ).on( 'click', function() {
        var $checkbox = $metaBox.find( '.menu-item-checkbox' ).first();

        if ( ! $checkbox.length ) {
            return;
        }

        $checkbox.prop( 'checked', true );
    } );
} );
