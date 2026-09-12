// ESS data sources.
//
// HR1 does not yet have backend tables/APIs for goals, evaluations,
// competency assessments, trainings, learning paths or recognitions, so
// these are intentionally EMPTY. The modules render their honest empty
// states ("No records available yet"). When a matching backend is added
// later, the modules will be wired to it instead of these arrays.

export const goals = [];
export const evaluations = [];
export const kpiCriteria = [];
export const competencyAssessments = [];
export const skillsGap = [];
export const ALL_COURSES = [];
export const recognitions = [];

export default { goals, evaluations, kpiCriteria, competencyAssessments, skillsGap, ALL_COURSES, recognitions };