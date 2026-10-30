-- Create holidays table
CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'Gazetted Holiday',
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_holiday_date (holiday_date),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clear previous test data if any
TRUNCATE TABLE holidays;

-- Seed 15 representative Festival, National, and Government Holidays
INSERT INTO holidays (name, holiday_date, day_of_week, type, description, image_url, is_optional) VALUES
('Republic Day', '2026-01-26', 'Monday', 'National Holiday', 'Celebrates the enactment of the Constitution of India with grandeur, flag ceremonies, and patriotic tributes across the country.', 'https://images.unsplash.com/photo-1596401057633-54a8fe8ef647?auto=format&fit=crop&w=800&q=80', 0),

('Maha Shivratri', '2026-02-15', 'Sunday', 'Festival', 'A solemn Hindu festival honoring Lord Shiva, characterized by night-long prayers, meditation, and temple visits.', 'https://images.unsplash.com/photo-1545063914-a1a6ec821c88?auto=format&fit=crop&w=800&q=80', 0),

('Holi (Festival of Colors)', '2026-03-04', 'Wednesday', 'Festival', 'The exuberant festival of colors celebrating the victory of good over evil, the arrival of spring, and community togetherness with organic gulal.', 'https://images.unsplash.com/photo-1576267423048-15c0040fec78?auto=format&fit=crop&w=800&q=80', 0),

('Eid-ul-Fitr', '2026-03-20', 'Friday', 'Festival', 'A major religious holiday celebrated by Muslims worldwide, marking the conclusion of the holy month of Ramadan with morning prayers, charity, and feasts.', 'https://images.unsplash.com/photo-1564769625905-50e93615e769?auto=format&fit=crop&w=800&q=80', 0),

('Mahavir Jayanti', '2026-03-31', 'Tuesday', 'Gazetted Holiday', 'Celebrates the birth anniversary of Lord Mahavira, preaching non-violence (Ahimsa), peace, and truthfulness.', 'https://images.unsplash.com/photo-1548013146-72479768bada?auto=format&fit=crop&w=800&q=80', 0),

('Good Friday', '2026-04-03', 'Friday', 'Gazetted Holiday', 'A Christian holiday commemorating the crucifixion and death of Jesus Christ at Calvary, observed with prayer and church services.', 'https://images.unsplash.com/photo-1519817650390-64a93db51149?auto=format&fit=crop&w=800&q=80', 0),

('Dr. B.R. Ambedkar Jayanti', '2026-04-14', 'Tuesday', 'Gazetted Holiday', 'Commemorates the birth anniversary of Dr. Bhimrao Ramji Ambedkar, architect of the Indian Constitution, statesman, and social reformer.', 'https://images.unsplash.com/photo-1589829545856-d10d557cf95f?auto=format&fit=crop&w=800&q=80', 0),

('May Day / International Labour Day', '2026-05-01', 'Friday', 'Public Holiday', 'A worldwide celebration honoring workers, social justice, and the historical struggles and achievements of the labor movement.', 'https://images.unsplash.com/photo-1504384308090-c894fdcc538d?auto=format&fit=crop&w=800&q=80', 0),

('Bakrid / Eid al-Adha', '2026-05-27', 'Wednesday', 'Festival', 'The Feast of the Sacrifice honoring the obedience and devotion of Ibrahim, celebrated with community prayers and festive gatherings.', 'https://images.unsplash.com/photo-1591604129939-f1efa4d9f7fa?auto=format&fit=crop&w=800&q=80', 0),

('Independence Day', '2026-08-15', 'Saturday', 'National Holiday', 'Honors India national independence from colonial rule in 1947, celebrated with flag hoisting ceremonies, cultural events, and national pride.', 'https://images.unsplash.com/photo-1532375810709-75b1da00537c?auto=format&fit=crop&w=800&q=80', 0),

('Raksha Bandhan', '2026-08-28', 'Friday', 'Festival', 'Celebrates the cherished bond of love and protection between siblings with sacred threads (Rakhis), sweets, and exchanged gifts.', 'https://images.unsplash.com/photo-1629853381643-4b684534f41b?auto=format&fit=crop&w=800&q=80', 1),

('Janmashtami', '2026-09-04', 'Friday', 'Festival', 'Celebrates the divine birth of Lord Krishna with devotional singing, fasting, Dahi Handi competitions, and night vigils.', 'https://images.unsplash.com/photo-1601050690597-df0568f70950?auto=format&fit=crop&w=800&q=80', 0),

('Mahatma Gandhi Jayanti', '2026-10-02', 'Friday', 'National Holiday', 'Marks the birth anniversary of Mohandas Karamchand Gandhi, revered father of the nation, celebrated as the International Day of Non-Violence.', 'https://images.unsplash.com/photo-1609137144820-fdc0205be80e?auto=format&fit=crop&w=800&q=80', 0),

('Dussehra (Vijayadashami)', '2026-10-20', 'Tuesday', 'Festival', 'Signifies the victory of righteousness over evil, celebrating Lord Rama defeat of Ravana and Goddess Durga vanquishing Mahishasura.', 'https://images.unsplash.com/photo-1604881991720-f91add269bed?auto=format&fit=crop&w=800&q=80', 0),

('Diwali (Deepavali)', '2026-11-08', 'Sunday', 'Festival', 'The glittering Festival of Lights celebrating spiritual triumph, illuminated homes with diyas, fireworks, festive feasts, and Lakshmi Puja.', 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?auto=format&fit=crop&w=800&q=80', 0),

('Christmas Day', '2026-12-25', 'Friday', 'Gazetted Holiday', 'Celebrates the birth of Jesus Christ with joyful carol singing, Christmas trees, exchange of presents, and goodwill to all.', 'https://images.unsplash.com/photo-1512474932049-78ac69ede12c?auto=format&fit=crop&w=800&q=80', 0);

-- Update menu_modules: Replace or add holidays module
INSERT INTO menu_modules (module_key, title, subtitle, icon, color, route, badge, is_active, sort_order)
VALUES ('holidays', 'Holiday Calendar', 'Festivals & Government Holidays', 'celebration', '#E11D48', 'holidays', '2026', 1, 10)
ON DUPLICATE KEY UPDATE 
    title = 'Holiday Calendar', 
    subtitle = 'Festivals & Government Holidays', 
    icon = 'celebration', 
    color = '#E11D48', 
    route = 'holidays', 
    badge = '2026',
    is_active = 1;
