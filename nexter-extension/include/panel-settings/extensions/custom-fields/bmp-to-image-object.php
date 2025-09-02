<?php 
/**
 * Convert BMP file to GD image object
 *
 * @link https://github.com/dompdf/dompdf/blob/master/src/Helpers.php
 * @since 4.3.0
 *
 * @param string $filename Absolute path to BMP file.
 * @param resource|null $context Optional stream context.
 * @return resource|false GD image resource on success, false on failure.
 */
defined('ABSPATH') or die();

if( !function_exists('nxt_bmp_to_image_object') ){
    function nxt_bmp_to_image_object( $filename, $context = null ) {
        $filename = sanitize_text_field( $filename );

        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            trigger_error( 'GD extension is required.', E_ERROR );
            return false;
        }

        $fh = @fopen( $filename, 'rb', false, $context );
        if ( ! $fh ) {
            // Sanitize filename to avoid log injection or unexpected characters
            trigger_error("Cannot open file: " . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'), E_USER_WARNING);
            return false;
        }

        // File header
        $header = fread( $fh, 14 );
        if ( strlen( $header ) < 14 ) {
            fclose( $fh );
            trigger_error( "Invalid BMP header in".htmlspecialchars( $filename, ENT_QUOTES, 'UTF-8' ), E_USER_WARNING );
            return false;
        }

        $meta = unpack( 'vtype/Vfilesize/Vreserved/Voffset', $header );
        if ( $meta['type'] !== 0x4D42 ) { // 'BM' in hex
            fclose( $fh );
            trigger_error( htmlspecialchars( $filename, ENT_QUOTES, 'UTF-8' )." is not a BMP file.", E_USER_WARNING );
            return false;
        }

        // Image header
        $dibHeader = fread( $fh, 40 );
        $meta += unpack( 'Vheadersize/Vwidth/Vheight/vplanes/vbits/Vcompression/Vimagesize/Vxres/Vyres/Vcolors/Vimportant', $dibHeader );
        $meta['bytes'] = $meta['bits'] / 8;
        $meta['decal'] = ( 4 - ( ( $meta['width'] * $meta['bytes'] ) % 4 ) ) % 4;

        // Optional bit masks for BI_BITFIELDS
        if ( $meta['compression'] === 3 ) {
            $bitfields = fread( $fh, 12 );
            $meta += unpack( 'VrMask/VgMask/VbMask', $bitfields );
        }

        // Calculate image size if missing
        if ( $meta['imagesize'] < 1 ) {
            $meta['imagesize'] = filesize( $filename ) - $meta['offset'];
        }

        // Color palette
        $palette = [];
        if ( $meta['bits'] < 16 ) {
            $palette = unpack( 'l' . $meta['colors'], fread( $fh, $meta['colors'] * 4 ) );
            foreach ( $palette as $i => $color ) {
                $palette[ $i ] = $color < 0 ? $color + 0x1000000 : $color;
            }
        }

        // Skip to image data
        fseek( $fh, $meta['offset'] );
        $data = fread( $fh, $meta['imagesize'] );
        fclose( $fh );

        // Decode RLE if needed (external helper assumed)
        if ( $meta['compression'] === 1 && class_exists( 'Helpers' ) ) {
            $data = Helpers::rle8_decode( $data, $meta['width'] );
        } elseif ( $meta['compression'] === 2 && class_exists( 'Helpers' ) ) {
            $data = Helpers::rle4_decode( $data, $meta['width'] );
        }

        $img = imagecreatetruecolor( $meta['width'], $meta['height'] );
        $p = 0;
        $y = $meta['height'] - 1;
        $emptyByte = chr( 0 );

        while ( $y >= 0 ) {
            $x = 0;
            while ( $x < $meta['width'] ) {
                switch ( $meta['bits'] ) {
                    case 24:
                        $part = substr( $data, $p, 3 );
                        $color = unpack( 'V', $part . $emptyByte );
                        break;
                    case 16:
                        $part = unpack( 'v', substr( $data, $p, 2 ) );
                        $colorVal = $part[1];
                        if ( empty( $meta['rMask'] ) || $meta['rMask'] !== 0xf800 ) {
                            $color[1] = ( ( $colorVal & 0x7C00 ) >> 7 ) << 16
                                    | ( ( $colorVal & 0x03E0 ) >> 2 ) << 8
                                    | ( $colorVal & 0x001F ) << 3;
                        } else {
                            $color[1] = ( ( $colorVal & 0xF800 ) >> 8 ) << 16
                                    | ( ( $colorVal & 0x07E0 ) >> 3 ) << 8
                                    | ( $colorVal & 0x001F ) << 3;
                        }
                        break;
                    case 8:
                        $idx = ord( $data[ $p ] );
                        $color[1] = $palette[ $idx + 1 ] ?? 0;
                        break;
                    default:
                        $safe_bits = htmlspecialchars( (string) $meta['bits'], ENT_QUOTES, 'UTF-8' );
                        trigger_error( "Unsupported BMP bit depth: {$safe_bits}", E_USER_WARNING );
                        return false;
                }
                imagesetpixel( $img, $x++, $y, $color[1] );
                $p += $meta['bytes'];
            }
            $p += $meta['decal'];
            $y--;
        }

        return $img;
    }
}
