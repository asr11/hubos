use rusqlite::{Connection, Result};
use serde::{Deserialize, Serialize};
use std::time::Duration;
use tokio::time;

#[derive(Serialize, Deserialize)]
struct ActionPayload {
    entity_id: String,
    entity_type: String,
    title: Option<String>,
    name: Option<String>,
    recommended_action: String,
}

fn is_action_already_proposed(conn: &Connection, action_type: &str, entity_id: &str) -> Result<bool> {
    let mut stmt = conn.prepare(
        "SELECT action_id FROM ceo_actions 
         WHERE action_type = ? AND status IN ('PROPOSED', 'APPROVED', 'EXECUTING')
         AND json_extract(proposed_payload, '$.entity_id') = ?"
    )?;
    
    let exists = stmt.exists([action_type, entity_id])?;
    Ok(exists)
}

fn propose_action(conn: &Connection, action_type: &str, reasoning: &str, payload: &ActionPayload) -> Result<()> {
    let action_id = format!("act_rust_{}", uuid::Uuid::new_v4().simple());
    let status = "PROPOSED";
    let payload_json = serde_json::to_string(payload).unwrap();
    let initial_ledger = format!(r#"[{{"status":"PROPOSED","timestamp":"{}","actor":"hub-core-rust","data":{}}}]"#, 
        chrono::Utc::now().to_rfc3339(), payload_json);

    conn.execute(
        "INSERT INTO ceo_actions (action_id, action_type, status, reasoning, proposed_payload, ledger)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
        (action_id.clone(), action_type, status, reasoning, &payload_json, initial_ledger),
    )?;

    println!("[RUST-CORE] Proposed Action: {} for entity {} ({})", action_type, payload.entity_id, action_id);
    Ok(())
}

fn scan_neglected_leads(os_db: &Connection, legacy_db: &Connection) -> Result<()> {
    let mut stmt = legacy_db.prepare(
        "SELECT id, name, created_at, status FROM leads 
         WHERE (status = 'new' OR status = 'contacted') 
         AND name NOT LIKE '%test%'
         AND name NOT LIKE '%dup%'
         AND name NOT LIKE '%board%'
         AND source != 'test'
         LIMIT 10"
    )?;

    let leads = stmt.query_map([], |row| {
        Ok((row.get::<_, i64>(0)?.to_string(), row.get::<_, String>(1)?, row.get::<_, String>(3)?))
    })?;

    for lead in leads {
        if let Ok((id, name, status)) = lead {
            if !is_action_already_proposed(os_db, "ESCALATE_NEGLECTED_LEAD", &id).unwrap_or(false) {
                let reasoning = format!("Lead ID {} ({}) is neglected. Status: {}.", id, name, status);
                let payload = ActionPayload {
                    entity_id: id,
                    entity_type: "leads".to_string(),
                    title: None,
                    name: Some(name),
                    recommended_action: "REASSIGN_TO_HUMAN".to_string(),
                };
                let _ = propose_action(os_db, "ESCALATE_NEGLECTED_LEAD", &reasoning, &payload);
            }
        }
    }
    Ok(())
}

fn scan_overdue_tasks(os_db: &Connection, legacy_db: &Connection) -> Result<()> {
    let mut stmt = legacy_db.prepare(
        "SELECT id, title, due_at, status FROM tasks 
         WHERE status != 'done' 
         AND title NOT LIKE '%test%' 
         AND title NOT LIKE '%dup%' 
         AND title NOT LIKE '%board%'
         AND due_at < datetime('now') 
         LIMIT 10"
    )?;

    let tasks = stmt.query_map([], |row| {
        Ok((row.get::<_, i64>(0)?.to_string(), row.get::<_, String>(1)?, row.get::<_, String>(2)?))
    })?;

    for task in tasks {
        if let Ok((id, title, due_at)) = task {
            if !is_action_already_proposed(os_db, "REVIEW_OVERDUE_TASK", &id).unwrap_or(false) {
                let reasoning = format!("Task ID {} (\"{}\") is overdue since {}.", id, title, due_at);
                let payload = ActionPayload {
                    entity_id: id,
                    entity_type: "tasks".to_string(),
                    title: Some(title),
                    name: None,
                    recommended_action: "REVIEW_TASK".to_string(),
                };
                let _ = propose_action(os_db, "REVIEW_OVERDUE_TASK", &reasoning, &payload);
            }
        }
    }
    Ok(())
}

#[tokio::main]
async fn main() -> Result<()> {
    println!("[RUST-CORE] Starting Sovereign Core Engine...");
    
    // Connect to databases
    let os_db_path = "../server/data/os_hub.db";
    let legacy_db_path = "../server/data/legacy_hub_os.db";
    
    let mut interval = time::interval(Duration::from_secs(300));
    
    loop {
        interval.tick().await;
        println!("[RUST-CORE] Running anomaly scanners...");
        
        let os_db = Connection::open(os_db_path);
        let legacy_db = Connection::open(legacy_db_path);
        
        if let Ok(os) = os_db {
            // let _ = scan_neglected_leads(&os, &leg);
            // let _ = scan_overdue_tasks(&os, &leg);
            println!("[RUST-CORE] Legacy scanning disabled as per policy. Listening for real-time os_hub events...");
        } else {
            eprintln!("[RUST-CORE] Error connecting to databases.");
        }
    }
}
