import math
from PIL import Image, ImageDraw, ImageFont

def draw_compass_logo(size, is_maskable=False):
    # Base canvas
    img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    
    # Background color (Terracotta)
    bg_color = (200, 75, 26, 255) # #c84b1a
    dark_accent = (150, 50, 15, 255)
    gold_color = (245, 195, 65, 255) # #f5c341
    white_color = (255, 255, 255, 255)
    
    # For maskable, background fills entire square
    if is_maskable:
        draw.rectangle([0, 0, size, size], fill=bg_color)
        padding = size * 0.2
    else:
        # Rounded rectangle
        corner_radius = size * 0.2
        draw.rounded_rectangle([0, 0, size, size], radius=corner_radius, fill=bg_color)
        padding = size * 0.15

    center_x = size / 2.0
    center_y = size / 2.0
    radius = (size - 2 * padding) / 2.0
    
    # Outer ring
    ring_width = max(2, int(size * 0.03))
    draw.ellipse(
        [center_x - radius, center_y - radius, center_x + radius, center_y + radius],
        outline=gold_color,
        width=ring_width
    )
    
    # Inner ring
    inner_radius = radius * 0.75
    draw.ellipse(
        [center_x - inner_radius, center_y - inner_radius, center_x + inner_radius, center_y + inner_radius],
        outline=white_color,
        width=max(1, int(ring_width * 0.6))
    )
    
    # Compass Needles / Star Points
    # North Needle (Gold)
    n_tip = (center_x, center_y - radius * 0.85)
    n_right = (center_x + radius * 0.2, center_y)
    n_left = (center_x - radius * 0.2, center_y)
    draw.polygon([center_x, center_y, n_right[0], n_right[1], n_tip[0], n_tip[1]], fill=gold_color)
    draw.polygon([center_x, center_y, n_left[0], n_left[1], n_tip[0], n_tip[1]], fill=white_color)

    # South Needle
    s_tip = (center_x, center_y + radius * 0.85)
    draw.polygon([center_x, center_y, n_left[0], n_left[1], s_tip[0], s_tip[1]], fill=dark_accent)
    draw.polygon([center_x, center_y, n_right[0], n_right[1], s_tip[0], s_tip[1]], fill=gold_color)
    
    # Center Cap
    cap_r = radius * 0.12
    draw.ellipse([center_x - cap_r, center_y - cap_r, center_x + cap_r, center_y + cap_r], fill=gold_color, outline=dark_accent, width=1)

    return img

if __name__ == '__main__':
    import os
    os.makedirs('public', exist_ok=True)
    
    # Generate 192x192
    img192 = draw_compass_logo(192)
    img192.save('public/pwa-192x192.png', 'PNG')
    
    # Generate 512x512
    img512 = draw_compass_logo(512)
    img512.save('public/pwa-512x512.png', 'PNG')
    
    # Generate Maskable 512x512
    img_mask = draw_compass_logo(512, is_maskable=True)
    img_mask.save('public/maskable-icon-512x512.png', 'PNG')
    
    # Generate Apple Touch Icon 180x180
    img_apple = draw_compass_logo(180)
    img_apple.save('public/apple-touch-icon.png', 'PNG')
    
    print("PWA icons generated successfully in public/")
