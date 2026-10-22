-- Special trip: a one-off trip whose amount is typed by hand (no fixed price).
ALTER TABLE trips MODIFY kind ENUM('goods','bus_in','bus_out','bus_empty','other') NOT NULL;
