-- Ejecutar manualmente solo despues de validar el flujo plural en produccion.
-- Las copias conservan estructura y datos para una restauracion controlada.

CREATE TABLE IF NOT EXISTS encuesta_legacy_backup LIKE encuesta;
INSERT IGNORE INTO encuesta_legacy_backup SELECT * FROM encuesta;

CREATE TABLE IF NOT EXISTS encuesta_escala_legacy_backup LIKE encuesta_escala;
INSERT IGNORE INTO encuesta_escala_legacy_backup SELECT * FROM encuesta_escala;

CREATE TABLE IF NOT EXISTS encuesta_pregunta_legacy_backup LIKE encuesta_pregunta;
INSERT IGNORE INTO encuesta_pregunta_legacy_backup SELECT * FROM encuesta_pregunta;

CREATE TABLE IF NOT EXISTS encuesta_pregunta_opcion_legacy_backup LIKE encuesta_pregunta_opcion;
INSERT IGNORE INTO encuesta_pregunta_opcion_legacy_backup SELECT * FROM encuesta_pregunta_opcion;

CREATE TABLE IF NOT EXISTS encuesta_respuesta_legacy_backup LIKE encuesta_respuesta;
INSERT IGNORE INTO encuesta_respuesta_legacy_backup SELECT * FROM encuesta_respuesta;

CREATE TABLE IF NOT EXISTS encuesta_respuesta_detalle_legacy_backup LIKE encuesta_respuesta_detalle;
INSERT IGNORE INTO encuesta_respuesta_detalle_legacy_backup SELECT * FROM encuesta_respuesta_detalle;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS encuesta_respuesta_detalle;
DROP TABLE IF EXISTS encuesta_respuesta;
DROP TABLE IF EXISTS encuesta_pregunta_opcion;
DROP TABLE IF EXISTS encuesta_pregunta;
DROP TABLE IF EXISTS encuesta_escala;
DROP TABLE IF EXISTS encuesta;
SET FOREIGN_KEY_CHECKS = 1;
