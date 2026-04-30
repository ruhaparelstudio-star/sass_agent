# Conversation Regression Scenario: Wedding Pricelist → Detail → Booking

## Goal
Pastikan sistem bisa menjaga konteks percakapan dari awal sampai booking handoff.

## Tenant Context
- Tenant name: Studio Wedding
- Industry: photography
- Available service:
  - wedding photography
  - wedding photo + video
- Pricelist file:
  - file: file_pricelist_wedding.pdf
  - service: wedding
- Booking link:
  - link: https://booking.example.com/wedding
  - service: wedding

## Scenario

### Turn 1
Client: siang ka

Expected agent:
- memperkenalkan diri sebagai asisten tenant
- bertanya kebutuhan client

Expected stored data:
- message inbound tersimpan
- message outbound tersimpan
- conversation created/open
- conversation_state.current_stage = greeting / new
- active_goal belum booking

Expected intent:
- greeting

Expected action:
- send_text

---

### Turn 2
Client: boleh minta pricelistnya

Expected agent:
- tidak langsung kirim pricelist
- minta nama client dulu

Expected stored data:
- intent = ask_pricelist
- active_goal = send_pricelist / collect_lead_info
- current_stage = collecting_name
- pending_action = send_pricelist
- missing_required_fields includes name

Expected action:
- send_text

---

### Turn 3
Client: nama aris egi

Expected agent:
- menyimpan nama aris egi
- bertanya layanan yang dicari

Expected stored data:
- lead_profile.name = aris egi
- known_entities.name = aris egi
- current_stage = collecting_service
- active_goal masih send_pricelist
- pending_action masih send_pricelist

Expected intent:
- provide_name

Expected action:
- send_text

---

### Turn 4
Client: photo graper wedding ka

Expected agent:
- resolve maksud client sebagai wedding photography
- mengirim file_pricelist_wedding.pdf
- mengirim text follow-up pricelist

Expected stored data:
- service_interest = wedding photography
- current_stage = pricelist_sent
- pending_action selesai / cleared
- outbound file tercatat di action_logs
- outbound file tercatat di messages atau minimal bisa direkonstruksi dari wa_outbound_messages
- decision trace mencatat action send_file success

Expected intent:
- ask_pricelist / provide_service

Expected action:
- send_file
- send_text

---

### Turn 5
Client: untuk detail photo videonya bisa dijelaskan ka

Expected agent:
- menjelaskan detail paket photo + video berdasarkan knowledge/catalog
- tidak bertanya ulang nama
- tidak bertanya ulang layanan dari nol

Expected stored data:
- recent context atau state terbaca kembali
- service_interest tetap wedding / photo + video
- current_stage = explaining_package

Expected intent:
- ask_package_detail

Expected action:
- send_text

---

### Turn 6
Client: ouh gitu yaudah aku mau ambil paket photo dan video ka

Expected agent:
- mengirim booking link
- melakukan handoff admin
- tidak mengklaim booking link terkirim kalau dispatch gagal

Expected stored data:
- selected_package = photo + video
- active_goal = booking
- current_stage = handoff / booking_requested
- agent_mode = handoff
- action_logs contains send_booking_link success
- action_logs contains handoff_to_human success
- decision_traces contains final dispatch outcome

Expected intent:
- booking_intent

Expected action:
- send_booking_link
- handoff_to_human