# Entity Relationship Overview

**Author:** Reza Rafiei

Area 1—N Goal  
Goal 1—N Project  
Project 1—N Milestone  
Milestone 1—N Task  
Goal 1—N Task  
Project 1—N Task  
Task 1—N child Task  
Task N—N Task through TaskDependency  
Task 1—N ScheduleBlock  
Task 1—N ExecutionLog  
Task N—1 FailureReason  
Task 1—N Reminder  
Goal/Project/Task 1—N Note  
Area 1—N Decision  
All important entities 1—N ActivityLog

DailyPlan 1—N ScheduleBlock is optional and should remain separate from task deadlines.