const fs = require( 'fs' );
const path = require( 'path' );
const ImageTracer = require( 'imagetracerjs' );
const { PNG } = require( 'pngjs' );

const root = path.resolve( __dirname, '..' );
const sourcePath = path.join( root, 'assets', 'logo.png' );
const lightPath = path.join( root, 'assets', 'logo-light.svg' );
const darkPath = path.join( root, 'assets', 'logo-dark.svg' );

const source = PNG.sync.read( fs.readFileSync( sourcePath ) );

const resizeImage = ( image, maxSize ) => {
	const scale = Math.min( 1, maxSize / Math.max( image.width, image.height ) );
	const width = Math.max( 1, Math.round( image.width * scale ) );
	const height = Math.max( 1, Math.round( image.height * scale ) );
	const data = Buffer.alloc( width * height * 4 );

	for ( let y = 0; y < height; y++ ) {
		for ( let x = 0; x < width; x++ ) {
			const sourceX = Math.min( image.width - 1, Math.round( x / scale ) );
			const sourceY = Math.min( image.height - 1, Math.round( y / scale ) );
			const sourceIndex = ( sourceY * image.width + sourceX ) * 4;
			const targetIndex = ( y * width + x ) * 4;

			data[ targetIndex ] = image.data[ sourceIndex ];
			data[ targetIndex + 1 ] = image.data[ sourceIndex + 1 ];
			data[ targetIndex + 2 ] = image.data[ sourceIndex + 2 ];
			data[ targetIndex + 3 ] = image.data[ sourceIndex + 3 ];
		}
	}

	return { width, height, data };
};

const image = resizeImage( source, 560 );
const data = Buffer.from( image.data );

const sampleBackground = () => {
	const samples = [];
	const size = Math.max( 12, Math.floor( Math.min( image.width, image.height ) * 0.05 ) );
	const corners = [
		[ 0, 0 ],
		[ image.width - size, 0 ],
		[ 0, image.height - size ],
		[ image.width - size, image.height - size ],
	];

	for ( const [ startX, startY ] of corners ) {
		for ( let y = startY; y < startY + size; y++ ) {
			for ( let x = startX; x < startX + size; x++ ) {
				const index = ( y * image.width + x ) * 4;
				samples.push( [ data[ index ], data[ index + 1 ], data[ index + 2 ] ] );
			}
		}
	}

	const total = samples.reduce(
		( acc, rgb ) => [ acc[ 0 ] + rgb[ 0 ], acc[ 1 ] + rgb[ 1 ], acc[ 2 ] + rgb[ 2 ] ],
		[ 0, 0, 0 ]
	);

	return total.map( ( value ) => value / samples.length );
};

const background = sampleBackground();
const threshold = 58;

for ( let index = 0; index < data.length; index += 4 ) {
	const distance = Math.sqrt(
		( data[ index ] - background[ 0 ] ) ** 2 +
			( data[ index + 1 ] - background[ 1 ] ) ** 2 +
			( data[ index + 2 ] - background[ 2 ] ) ** 2
	);

	if ( distance < threshold ) {
		data[ index + 3 ] = 0;
	}
}

const traced = ImageTracer.imagedataToSVG(
	{
		width: image.width,
		height: image.height,
		data,
	},
	{
		colorsampling: 2,
		numberofcolors: 28,
		pathomit: 24,
		ltres: 1.5,
		qtres: 1.5,
		roundcoords: 2,
		viewbox: true,
		strokewidth: 0,
	}
);

const visiblePaths = [ ...traced.matchAll( /<path\b[^>]*>/g ) ]
	.map( ( match ) => match[ 0 ] )
	.filter( ( pathString ) => ! pathString.includes( 'opacity="0"' ) );

const coordinates = visiblePaths.flatMap( ( pathString ) => {
	const pathData = pathString.match( /d="([^"]+)"/ );

	if ( ! pathData ) {
		return [];
	}

	return [ ...pathData[ 1 ].matchAll( /-?\d+(?:\.\d+)?/g ) ].map( ( match ) =>
		Number( match[ 0 ] )
	);
} );

const xs = coordinates.filter( ( _, index ) => index % 2 === 0 );
const ys = coordinates.filter( ( _, index ) => index % 2 === 1 );
const padding = 12;
const minX = Math.max( 0, Math.min( ...xs ) - padding );
const minY = Math.max( 0, Math.min( ...ys ) - padding );
const maxX = Math.min( image.width, Math.max( ...xs ) + padding );
const maxY = Math.min( image.height, Math.max( ...ys ) + padding );
const viewBox = `${ minX.toFixed( 2 ) } ${ minY.toFixed( 2 ) } ${ ( maxX - minX ).toFixed( 2 ) } ${ ( maxY - minY ).toFixed( 2 ) }`;

const recolorPath = ( pathString, mapping ) =>
	Object.entries( mapping ).reduce(
		( output, [ from, to ] ) =>
			output
				.replaceAll( `fill="${ from }"`, `fill="${ to }"` )
				.replaceAll( `stroke="${ from }"`, `stroke="${ to }"` ),
		pathString
	);

const makeSvg = ( title, description, mapping ) => `<svg viewBox="${ viewBox }" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="title desc">
  <title id="title">${ title }</title>
  <desc id="desc">${ description }</desc>
  ${ visiblePaths.map( ( pathString ) => recolorPath( pathString, mapping ) ).join( '\n  ' ) }
</svg>
`;

fs.writeFileSync(
	lightPath,
	makeSvg( 'Gutenwright logo', 'Vectorized Gutenwright logo without a background.', {} )
);
fs.writeFileSync(
	darkPath,
	makeSvg( 'Gutenwright logo dark', 'Darker vector Gutenwright logo without a background.', {
		'rgb(14,56,253)': '#0F172A',
		'rgb(23,64,253)': '#1E293B',
		'rgb(161,181,239)': '#475569',
		'rgb(13,24,53)': '#020617',
	} )
);

console.log( `Wrote ${ path.relative( root, lightPath ) }` );
console.log( `Wrote ${ path.relative( root, darkPath ) }` );
