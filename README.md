# Dizzy Ticket Manager

Ticket sales, Mollie payments, QR tickets, reports and attendance management for Dizzy Events Manager.

## Mobile web check-in

After activating version 1.8.1 or newer, open:

`https://your-site.example/check-in/`

Sign in with an Administrator or Controller account. The page shows today's attendance totals and tickets, supports QR scanning with the rear camera, and includes manual ticket URL/code entry as a fallback.

Camera access requires HTTPS and browser permission. On Android Chrome, the page can be added to the home screen for app-like access.

## Reservation checkout integration

Version 1.10.0 exposes an internal WordPress checkout bridge for Dizzy Reservations Manager. A Dinner + Concert reservation can create a standard-ticket Mollie order, redirect the visitor to payment, and receive verified order-status callbacks. The bridge does not trust browser payment parameters; reservation confirmation is driven by the ticket order synchronized with Mollie/webhook data.
